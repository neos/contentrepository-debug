<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\EventGraveyard;

/**
 * @internal Display DTO for a single event that was moved to the graveyard during a fault-tolerant replay.
 * @see EventGraveyardTool for the tool that produces these entries.
 */
final readonly class GraveyardEntry
{
    public function __construct(
        public int $sequenceNumber,
        public string $eventType,
        public string $streamName,
        /** Comma-separated list of subscription IDs that failed on this event */
        public string $failedSubscriptions,
        public string $firstErrorMessage,
    ) {
    }
}
