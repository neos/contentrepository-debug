<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\ContentRepository;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainer;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\Subscription\DetachedSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\ProjectionSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\Flow\Annotations as Flow;

/**
 * @internal Displays subscription status overview, detailed error info for broken projections,
 *           and DB table sizes for the current CR.
 * @see ContentRepositoryMaintainer::status() for the underlying status API.
 */
#[ToolMeta(shortName: 'status', group: 'ContentRepository')]
#[Flow\Scope('singleton')]
final class StatusTool implements ToolInterface
{
    #[Flow\Inject]
    protected Connection $dbal;

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Subscription status & errors';
    }

    public function execute(
        ToolIOInterface $io,
        ContentRepositoryMaintainer $maintainer,
        ContentRepositoryId $cr,
    ): ?ToolContext {
        try {
            $crStatus = $maintainer->status();
        } catch (\Throwable $e) {
            $io->writeError('Could not retrieve status: ' . $e->getMessage());
            return null;
        }

        $positionInfo = $crStatus->eventStorePosition !== null
            ? 'Event store sequence_number: ' . $crStatus->eventStorePosition->value
            : 'Event store sequence_number: unknown';
        $io->writeLine($positionInfo);

        $rows = [];
        $errorDetails = [];

        foreach ($crStatus->subscriptionStatus as $status) {
            if ($status instanceof DetachedSubscriptionStatus) {
                $rows[] = [
                    $status->subscriptionId->value,
                    'DETACHED',
                    (string) $status->subscriptionPosition->value,
                ];
                continue;
            }

            if ($status instanceof ProjectionSubscriptionStatus) {
                $statusLabel = $status->subscriptionStatus->value;
                $rows[] = [
                    $status->subscriptionId->value,
                    $statusLabel,
                    (string) $status->subscriptionPosition->value,
                ];

                if ($status->subscriptionStatus === SubscriptionStatus::ERROR && $status->subscriptionError !== null) {
                    $errorDetails[] = $status;
                }
            }
        }

        if ($rows === []) {
            $io->writeNote('No subscriptions registered. Run ./flow cr:setup first.');
            return null;
        }

        $io->writeTable(['Subscription', 'Status', 'Position'], $rows);

        $this->writeTableSizes($io, $cr);

        foreach ($errorDetails as $status) {
            $io->writeLine('');
            $io->writeError('Error in ' . $status->subscriptionId->value);
            $io->writeKeyValue([
                'Previous status' => $status->subscriptionError->previousStatus->value,
                'Message' => $status->subscriptionError->errorMessage,
            ]);
            if ($status->subscriptionError->errorTrace !== null) {
                $io->writeLine('');
                $io->writeLine('Stack trace:');
                $io->writeLine($status->subscriptionError->errorTrace);
            }
        }

        return null;
    }

    private function writeTableSizes(ToolIOInterface $io, ContentRepositoryId $cr): void
    {
        $prefix = 'cr_' . $cr->value . '_';
        /** @var list<string> $tables */
        $tables = $this->dbal->fetchFirstColumn(
            'SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE()
             AND table_name LIKE :prefix
             ORDER BY table_name',
            ['prefix' => $prefix . '%']
        );

        if ($tables === []) {
            return;
        }

        $rows = array_map(
            fn(string $table) => [$table, (string)(int)$this->dbal->fetchOne("SELECT COUNT(*) FROM {$table}")],
            $tables,
        );

        $io->writeLine('');
        $io->writeTable(['Table', 'Rows'], $rows);
    }
}
