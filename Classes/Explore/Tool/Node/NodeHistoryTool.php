<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Node;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepositoryRegistry\Factory\EventStore\DoctrineEventStoreFactory;
use Neos\Flow\Annotations as Flow;

/**
 * @internal Shows the event history for a node aggregate by querying the event store directly.
 *
 * Searches the event payload JSON for the nodeAggregateId to find all events that affected this node.
 */
final class NodeHistoryTool implements ToolInterface
{
    #[Flow\Inject]
    protected Connection $connection;

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Node: event history';
    }

    public function execute(
        ToolIOInterface $io,
        ContentRepositoryId $cr,
        NodeAggregateId $node,
    ): ?ToolContext {
        $tableName = DoctrineEventStoreFactory::databaseTableName($cr);

        $qb = $this->connection->createQueryBuilder();
        $qb->select('sequencenumber', 'type', 'payload', 'recordedat')
            ->from($tableName)
            ->where('JSON_EXTRACT(payload, :jsonPath) = :nodeId')
            ->setParameter('jsonPath', '$.nodeAggregateId')
            ->setParameter('nodeId', $node->value)
            ->orderBy('sequencenumber', 'ASC');

        try {
            $result = $qb->executeQuery();
        } catch (\Throwable $e) {
            $io->writeError('Failed to query event store: ' . $e->getMessage());
            return null;
        }

        $events = $result->fetchAllAssociative();

        if ($events === []) {
            $io->writeLine('No events found for this node aggregate.');
            return null;
        }

        $io->writeLine(sprintf('<comment>%d events for node %s</comment>', count($events), $node->value));
        $io->writeLine('');

        $rows = [];
        foreach ($events as $event) {
            $type = $event['type'];
            // Shorten event type: "NodePropertiesWereSet" from "Neos.ContentRepository:NodePropertiesWereSet"
            $shortType = str_contains($type, ':') ? substr($type, strrpos($type, ':') + 1) : $type;

            $rows[] = [
                $event['sequencenumber'],
                $shortType,
                $event['recordedat'],
                $this->summarizePayload($event['payload'], $shortType),
            ];
        }

        $io->writeTable(['Seq', 'Event', 'Recorded at', 'Summary'], $rows);

        return null;
    }

    private function summarizePayload(string $payloadJson, string $eventType): string
    {
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return '';
        }

        return match (true) {
            str_contains($eventType, 'PropertiesWereSet') => $this->summarizeProperties($payload),
            str_contains($eventType, 'WasCreated'),
            str_contains($eventType, 'WasMoved'),
            str_contains($eventType, 'WasRemoved'),
            str_contains($eventType, 'WasTagged'),
            str_contains($eventType, 'WasUntagged') => $this->summarizeGeneric($payload),
            str_contains($eventType, 'ReferenceWasSet'),
            str_contains($eventType, 'ReferencesWereSet') => $this->summarizeReferences($payload),
            default => $this->summarizeGeneric($payload),
        };
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

    private function summarizeGeneric(array $payload): string
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
