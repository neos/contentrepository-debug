<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Node;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountBackReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountChildNodesFilter;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\AutoRunToolInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Displays a node's identity and key context info. Auto-runs when a node is first selected.
 *
 * @see ContentGraphInterface::findNodeAggregateById() for the underlying lookup.
 */
final class NodeIdentityTool implements AutoRunToolInterface
{
    public function getMenuLabel(ToolContext $context): string
    {
        return 'Node: identity';
    }

    public function execute(
        ToolIOInterface $io,
        ContentGraphInterface $contentGraph,
        NodeAggregateId $node,
        ?ContentSubgraphInterface $subgraph = null,
    ): ?ToolContext {
        $aggregate = $contentGraph->findNodeAggregateById($node);
        if ($aggregate === null) {
            $io->writeError(sprintf('Node aggregate "%s" not found.', $node->value));
            return null;
        }

        $parents = $contentGraph->findParentNodeAggregates($node);
        $parentInfo = [];
        foreach ($parents as $parent) {
            $parentInfo[] = sprintf('%s (%s)', $parent->nodeAggregateId->value, $parent->nodeTypeName->value);
        }

        $pairs = [
            'ID' => $aggregate->nodeAggregateId->value,
            'Type' => $aggregate->nodeTypeName->value,
            'Name' => $aggregate->nodeName?->value ?? '(none)',
            'Classification' => $aggregate->classification->value,
            'Parents' => $parentInfo !== [] ? implode(', ', $parentInfo) : '(root)',
        ];

        // Subgraph-dependent info (when workspace + DSP are set)
        if ($subgraph !== null) {
            $foundNode = $subgraph->findNodeById($node);
            if ($foundNode !== null) {
                $propertyCount = iterator_count($foundNode->properties->serialized());
                $childCount = $subgraph->countChildNodes($node, CountChildNodesFilter::create());
                $refCount = $subgraph->countReferences($node, CountReferencesFilter::create());
                $backRefCount = $subgraph->countBackReferences($node, CountBackReferencesFilter::create());

                $pairs['Properties'] = (string)$propertyCount;
                $pairs['Children'] = (string)$childCount;
                $pairs['References out'] = (string)$refCount;
                $pairs['References in'] = (string)$backRefCount;

                $timestamps = $foundNode->timestamps;
                $pairs['Created'] = $timestamps->originalCreated->format('Y-m-d H:i:s');
                $pairs['Last modified'] = $timestamps->originalLastModified?->format('Y-m-d H:i:s') ?? '(never)';
            }
        }

        $io->writeKeyValue($pairs);

        return null;
    }
}
