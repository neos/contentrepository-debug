<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Status;

use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainer;
use Neos\ContentRepository\Core\Subscription\DetachedSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\ProjectionSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Displays subscription status overview and detailed error info for broken projections.
 * @see ContentRepositoryMaintainer::status() for the underlying status API.
 */
#[ToolMeta(shortName: 'subStatus', group: 'Events')]
final class SubscriptionStatusTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string
    {
        return 'Subscription status & errors';
    }

    public function execute(
        ToolIOInterface $io,
        ContentRepositoryMaintainer $maintainer,
    ): ?ToolContext {
        try {
            $crStatus = $maintainer->status();
        } catch (\Throwable $e) {
            $io->writeError('Could not retrieve status: ' . $e->getMessage());
            return null;
        }

        $positionInfo = $crStatus->eventStorePosition !== null
            ? 'Event store position: ' . $crStatus->eventStorePosition->value
            : 'Event store position: unknown';
        $io->writeLine($positionInfo);
        $io->writeLine('');

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
}
