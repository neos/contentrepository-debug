<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\EventStore;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainer;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\Subscription\ProjectionSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\ContentRepository\Debug\EventGraveyard\GraveyardEntry;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepositoryRegistry\Factory\EventStore\DoctrineEventStoreFactory;
use Neos\Flow\Core\Bootstrap;

/**
 * @internal Moves events that cause subscription errors to a graveyard table, allowing subscriptions to recover.
 *
 * Uses {@see ContentRepositoryMaintainer::status()} to identify ERROR subscriptions and
 * {@see ContentRepositoryMaintainer::reactivateSubscription()} for recovery attempts.
 * For each selected subscription the bad event (next after last successful position) is moved
 * to a graveyard table, then the subscription is reactivated. The cycle repeats until the
 * subscription reaches ACTIVE or no more events remain.
 *
 * Only available in Development context.
 *
 * @see GraveyardEntry for the display DTO produced by this tool.
 */
#[ToolMeta(shortName: 'catchUpErrored', group: 'ContentRepository')]
final class EventGraveyardTool implements ToolInterface
{
    public function __construct(
        private readonly ContentRepositoryMaintainer $maintainer,
        private readonly Connection $dbal,
        private readonly Bootstrap $bootstrap,
        private readonly ContentRepositoryId $cr,
    ) {}

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Graveyard catch-up: skip bad events, move to graveyard (DEV only)';
    }

    public function execute(ToolIOInterface $io): ?ToolContext
    {
        if (!$this->bootstrap->getContext()->isDevelopment()) {
            throw new \LogicException('EventGraveyardTool may only run in Development context.', 1748000001);
        }

        // --- Identify errored subscriptions via public API ---
        $erroredStatuses = [];
        foreach ($this->maintainer->status()->subscriptionStatus as $status) {
            if ($status instanceof ProjectionSubscriptionStatus
                && $status->subscriptionStatus === SubscriptionStatus::ERROR) {
                $erroredStatuses[] = $status;
            }
        }

        if ($erroredStatuses === []) {
            $io->writeInfo('No subscriptions in ERROR status on "' . $this->cr->value . '". Nothing to do.');
            return null;
        }

        // --- Let user choose which errored subscriptions to process ---
        $choices = [];
        $defaults = [];
        foreach ($erroredStatuses as $status) {
            $key = $status->subscriptionId->value;
            $errorMsg = $status->subscriptionError?->errorMessage ?? '(no details)';
            $choices[$key] = sprintf(
                '%s at pos %d: %s',
                $key,
                $status->subscriptionPosition->value,
                substr($errorMsg, 0, 70),
            );
            $defaults[] = $key;
        }
        $selectedKeys = $io->chooseMultiple(
            'Select errored subscriptions to process (all enabled by default):',
            $choices,
            $defaults,
        );
        if ($selectedKeys === []) {
            $io->writeLine('No subscriptions selected.');
            return null;
        }

        $io->writeLine(sprintf(
            'Will move bad events to graveyard and reactivate %d subscription(s) on "%s".',
            count($selectedKeys),
            $this->cr->value,
        ));

        // --- Per-subscription graveyard loop ---
        $eventsTable = DoctrineEventStoreFactory::databaseTableName($this->cr);
        $graveyardTable = $this->graveyardTableName($this->cr);
        $this->setupGraveyardTable($graveyardTable);

        /** @var array<int, list<array{subscriptionId: string, errorMessage: string}>> $allSkippedEvents */
        $allSkippedEvents = [];

        foreach ($selectedKeys as $subIdStr) {
            $subscriptionId = SubscriptionId::fromString($subIdStr);

            $io->task(
                sprintf('Processing "%s"', $subIdStr),
                function (callable $log) use ($subscriptionId, $eventsTable, $graveyardTable, &$allSkippedEvents): void {
                    $skippedCount = 0;

                    for ($attempt = 0; $attempt < 10_000; $attempt++) {
                        $currentStatus = $this->getSubscriptionStatus($subscriptionId);
                        if ($currentStatus === null || $currentStatus->subscriptionStatus !== SubscriptionStatus::ERROR) {
                            $log(sprintf(
                                'Subscription now %s after skipping %d event(s).',
                                $currentStatus?->subscriptionStatus->value ?? 'unknown',
                                $skippedCount,
                            ));
                            break;
                        }

                        $subPosition = $currentStatus->subscriptionPosition->value;
                        $badEventSeq = $this->findNextEventSequenceNumber($eventsTable, $subPosition);
                        if ($badEventSeq === null) {
                            $log('No more events after position ' . $subPosition . '.');
                            break;
                        }

                        $errorMessage = $currentStatus->subscriptionError?->errorMessage ?? '(no details)';
                        $allSkippedEvents[$badEventSeq][] = [
                            'subscriptionId' => $subscriptionId->value,
                            'errorMessage' => $errorMessage,
                        ];

                        $this->moveSingleEventToGraveyard(
                            $badEventSeq,
                            $subscriptionId->value,
                            $errorMessage,
                            $eventsTable,
                            $graveyardTable,
                        );
                        $skippedCount++;
                        $log(sprintf('Skipped event #%d (total: %d)', $badEventSeq, $skippedCount));

                        $error = $this->maintainer->reactivateSubscription($subscriptionId);
                        if ($error === null) {
                            $log(sprintf('Subscription ACTIVE after skipping %d event(s).', $skippedCount));
                            break;
                        }
                        $log(sprintf('Still errored: %s', substr($error->getMessage(), 0, 80)));
                    }
                },
            );
        }

        if ($allSkippedEvents === []) {
            $io->writeInfo('No bad events found. All selected subscriptions recovered on "' . $this->cr->value . '".');
            return null;
        }

        // --- Show skipped events ---
        $entries = $this->buildGraveyardEntries($allSkippedEvents, $graveyardTable);
        $io->writeLine('');
        $io->writeNote(sprintf('Moved %d bad event(s) to graveyard:', count($allSkippedEvents)));
        $io->writeTable(
            ['Seq#', 'Type', 'Stream', 'Failed subscriptions', 'First error'],
            array_map(fn(GraveyardEntry $e) => [
                (string)$e->sequenceNumber, $e->eventType, $e->streamName,
                $e->failedSubscriptions, substr($e->firstErrorMessage, 0, 80),
            ], $entries),
        );

        // --- Show full graveyard + offer restore ---
        $this->showGraveyard($io, $this->cr, $graveyardTable);

        // --- Suggest next steps ---
        $io->writeLine('');
        $io->writeNote('Subscriptions are now ACTIVE but projections may be inconsistent.');
        $io->writeLine('Recommended next steps:');
        $io->writeLine('  1. Use "resetProjections" tool to reset all projections');
        $io->writeLine('  2. Then use "catchUp" tool to replay from scratch');
        $io->writeLine('  Or: ./flow subscription:replayAll from CLI');

        return null;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function graveyardTableName(ContentRepositoryId $crId): string
    {
        return DoctrineEventStoreFactory::databaseTableName($crId) . '_graveyard';
    }

    private function setupGraveyardTable(string $graveyardTable): void
    {
        $this->dbal->executeStatement("
            CREATE TABLE IF NOT EXISTS {$graveyardTable} (
                sequencenumber BIGINT NOT NULL,
                stream VARCHAR(255) NOT NULL,
                type VARCHAR(255) NOT NULL,
                payload JSON,
                metadata JSON,
                recordedat DATETIME NOT NULL,
                failed_subscriptions JSON NOT NULL,
                first_error_message LONGTEXT NOT NULL,
                graveyard_added_at DATETIME NOT NULL,
                PRIMARY KEY (sequencenumber)
            )
        ");
    }

    private function getSubscriptionStatus(SubscriptionId $id): ?ProjectionSubscriptionStatus
    {
        foreach ($this->maintainer->status()->subscriptionStatus as $status) {
            if ($status instanceof ProjectionSubscriptionStatus
                && $status->subscriptionId->equals($id)) {
                return $status;
            }
        }
        return null;
    }

    private function findNextEventSequenceNumber(string $eventsTable, int $afterPosition): ?int
    {
        $result = $this->dbal->fetchOne(
            "SELECT MIN(sequencenumber) FROM {$eventsTable} WHERE sequencenumber > :pos",
            ['pos' => $afterPosition],
        );
        return $result !== false && $result !== null ? (int)$result : null;
    }

    /**
     * Move a single event to graveyard atomically. If the event is already graveyarded
     * (e.g. by a previous subscription's loop), appends the subscription to failed_subscriptions.
     */
    private function moveSingleEventToGraveyard(
        int $seqNo,
        string $subscriptionId,
        string $errorMessage,
        string $eventsTable,
        string $graveyardTable,
    ): void {
        $this->dbal->transactional(function () use ($seqNo, $subscriptionId, $errorMessage, $eventsTable, $graveyardTable): void {
            $existing = $this->dbal->fetchAssociative(
                "SELECT failed_subscriptions FROM {$graveyardTable} WHERE sequencenumber = :seq",
                ['seq' => $seqNo],
            );

            if ($existing !== false) {
                // Already graveyarded by another subscription — append this subscription ID
                $subs = (array)json_decode((string)$existing['failed_subscriptions'], true);
                if (!in_array($subscriptionId, $subs, true)) {
                    $subs[] = $subscriptionId;
                    $this->dbal->executeStatement(
                        "UPDATE {$graveyardTable} SET failed_subscriptions = :subs WHERE sequencenumber = :seq",
                        ['subs' => json_encode($subs, JSON_THROW_ON_ERROR), 'seq' => $seqNo],
                    );
                }
                return;
            }

            $this->dbal->executeStatement(
                "INSERT INTO {$graveyardTable}
                    (sequencenumber, stream, type, payload, metadata, recordedat,
                     failed_subscriptions, first_error_message, graveyard_added_at)
                 SELECT sequencenumber, stream, type, payload, metadata, recordedat,
                        :failedSubs, :firstError, NOW()
                 FROM {$eventsTable} WHERE sequencenumber = :seq",
                [
                    'seq' => $seqNo,
                    'failedSubs' => json_encode([$subscriptionId], JSON_THROW_ON_ERROR),
                    'firstError' => $errorMessage,
                ],
            );
            $this->dbal->executeStatement(
                "DELETE FROM {$eventsTable} WHERE sequencenumber = :seq",
                ['seq' => $seqNo],
            );
        });
    }

    /**
     * @param array<int, list<array{subscriptionId: string, errorMessage: string}>> $skippedEvents
     * @return list<GraveyardEntry>
     */
    private function buildGraveyardEntries(array $skippedEvents, string $graveyardTable): array
    {
        $entries = [];
        foreach ($skippedEvents as $seqNo => $failures) {
            $row = $this->dbal->fetchAssociative(
                "SELECT type, stream FROM {$graveyardTable} WHERE sequencenumber = :seq",
                ['seq' => $seqNo],
            );
            $entries[] = new GraveyardEntry(
                sequenceNumber: $seqNo,
                eventType: $row['type'] ?? '?',
                streamName: $row['stream'] ?? '?',
                failedSubscriptions: implode(', ', array_unique(array_column($failures, 'subscriptionId'))),
                firstErrorMessage: $failures[0]['errorMessage'] ?? '',
            );
        }
        return $entries;
    }

    private function showGraveyard(ToolIOInterface $io, ContentRepositoryId $crId, string $graveyardTable): void
    {
        $entries = $this->dbal->fetchAllAssociative(
            "SELECT sequencenumber, type, stream, failed_subscriptions, first_error_message, graveyard_added_at
             FROM {$graveyardTable} ORDER BY sequencenumber"
        );
        if ($entries === []) {
            return;
        }

        $io->writeLine('');
        $io->writeNote('Graveyard contents for "' . $crId->value . '":');
        $io->writeTable(
            ['Seq#', 'Type', 'Stream', 'Failed subscriptions', 'First error', 'Added at'],
            array_map(fn(array $r) => [
                (string)$r['sequencenumber'], $r['type'], $r['stream'],
                implode(', ', (array)json_decode((string)$r['failed_subscriptions'], true)),
                substr($r['first_error_message'], 0, 60),
                $r['graveyard_added_at'],
            ], $entries),
        );
    }
}
