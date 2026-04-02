<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\EventStore;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepository\Debug\InternalServices\EventStoreDebuggingInternalsFactory;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\ContentRepositoryRegistry\Factory\EventStore\DoctrineEventStoreFactory;

/**
 * @internal Catches up all subscriptions on the selected CR from any state.
 *
 * Runs boot() for BOOTING subscriptions, catchUpActive() for ACTIVE ones, and
 * reactivate() for ERROR/DETACHED ones — in sequence with a single progress bar.
 * Uses batched transactions (1 000 events/batch).
 * If errors remain after all passes, re-run the tool or use {@see EventGraveyardTool}
 * to isolate the bad events first.
 */
#[ToolMeta(shortName: 'catchUp', group: 'ContentRepository')]
final class CatchUpTool implements ToolInterface
{
    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly ContentRepositoryRegistry $crRegistry,
        private readonly Connection $dbal,
        private readonly ContentRepositoryId $cr,
    ) {}

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Catch-up: boot + catchUp + reactivate all subscriptions';
    }

    public function execute(ToolIOInterface $io): ?ToolContext
    {
        $internals = $this->crRegistry->buildService($this->cr, new EventStoreDebuggingInternalsFactory());

        $minPos = null;
        foreach ($internals->subscriptionEngine->subscriptionStatus() as $sub) {
            if (in_array($sub->subscriptionStatus, [
                SubscriptionStatus::BOOTING,
                SubscriptionStatus::ACTIVE,
                SubscriptionStatus::ERROR,
                SubscriptionStatus::DETACHED,
            ], true)) {
                $pos = $sub->subscriptionPosition->value;
                $minPos = $minPos === null ? $pos : min($minPos, $pos);
            }
        }

        // Count actual events rather than using sequence-number arithmetic — gaps from compaction
        // would otherwise inflate the total and make the progress bar meaningless.
        $eventsTable = DoctrineEventStoreFactory::databaseTableName($this->cr);
        $eventsToProcess = max(1, (int)$this->dbal->fetchOne(
            "SELECT COUNT(*) FROM {$eventsTable} WHERE sequencenumber > :minPos",
            ['minPos' => $minPos ?? PHP_INT_MAX],
        ));

        $bootResult = null;
        $catchUpResult = null;
        $reactivateResult = null;

        $io->progress(
            sprintf('Catching up "%s" (~%d events)', $this->cr->value, $eventsToProcess),
            $eventsToProcess,
            function (callable $advance) use ($internals, &$bootResult, &$catchUpResult, &$reactivateResult): void {
                $bootResult = $internals->subscriptionEngine->boot(
                    progressCallback: static function () use ($advance): void { $advance(); },
                    batchSize: self::BATCH_SIZE,
                );
                $catchUpResult = $internals->subscriptionEngine->catchUpActive(
                    progressCallback: static function () use ($advance): void { $advance(); },
                    batchSize: self::BATCH_SIZE,
                );
                $reactivateResult = $internals->subscriptionEngine->reactivate(
                    progressCallback: static function () use ($advance): void { $advance(); },
                    batchSize: self::BATCH_SIZE,
                );
            }
        );

        $errors = array_merge(
            iterator_to_array($bootResult?->errors ?? []),
            iterator_to_array($catchUpResult?->errors ?? []),
            iterator_to_array($reactivateResult?->errors ?? []),
        );

        if ($errors !== []) {
            $io->writeError('Catch-up finished with errors. Re-run the tool or use "graveyardCatchUp" to isolate bad events.');
            foreach ($errors as $error) {
                $io->writeKeyValue([
                    'Subscription' => $error->subscriptionId->value,
                    'Message' => $error->message,
                ]);
            }
        } else {
            $io->writeInfo('All subscriptions active on "' . $this->cr->value . '".');
        }

        return null;
    }
}
