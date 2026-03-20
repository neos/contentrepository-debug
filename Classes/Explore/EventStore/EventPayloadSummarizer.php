<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\EventStore;

/**
 * @internal Extracts a human-readable summary from event payload JSON for display in event listings.
 */
final class EventPayloadSummarizer
{
    public function summarize(string $payloadJson, string $eventType): string
    {
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return '';
        }

        $parts = [];
        // Common marker-interface fields shown first for all event types
        if (isset($payload['workspaceName'])) {
            $parts[] = 'ws:' . $payload['workspaceName'];
        }
        if (isset($payload['nodeAggregateId'])) {
            $parts[] = 'node:' . $payload['nodeAggregateId'];
        } elseif (isset($payload['contentStreamId'])) {
            $parts[] = 'cs:' . substr($payload['contentStreamId'], 0, 8);
        }

        $detail = match (true) {
            str_contains($eventType, 'PropertiesWereSet') => $this->summarizeProperties($payload),
            str_contains($eventType, 'ReferenceWasSet'),
            str_contains($eventType, 'ReferencesWereSet') => $this->summarizeReferences($payload),
            default => $this->summarizeGenericExtra($payload),
        };
        if ($detail !== '') {
            $parts[] = $detail;
        }

        return implode(' / ', $parts);
    }

    private function summarizeProperties(array $payload): string
    {
        $props = $payload['propertyValues'] ?? [];
        $names = array_keys($props);
        if ($names === []) {
            return '';
        }
        $list = implode(', ', array_slice($names, 0, 5));
        return count($names) > 5 ? $list . ' (+' . (count($names) - 5) . ')' : $list;
    }

    private function summarizeReferences(array $payload): string
    {
        $name = $payload['referenceName'] ?? '?';
        $refs = $payload['references'] ?? [];
        return sprintf('%s (%d refs)', $name, count($refs));
    }

    /**
     * Appends event-specific fields not already covered by the common marker-interface prefix.
     * {@see summarize()} for the full assembly.
     */
    private function summarizeGenericExtra(array $payload): string
    {
        $parts = [];
        if (isset($payload['nodeTypeName'])) {
            $parts[] = $payload['nodeTypeName'];
        }
        if (isset($payload['tag'])) {
            $parts[] = 'tag:' . $payload['tag'];
        }
        return implode(' ', $parts);
    }
}
