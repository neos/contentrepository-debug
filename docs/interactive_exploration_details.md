# Explore Tools — V2 Implementation Plan

Planned tools for the next iteration. Builds on the existing V1 tools (see `interactive_exploration.md`).

---

## UX Improvements (do first)

### 1. Symfony Console formatting for headlines

Use `<info>`, `<comment>`, `<question>` tags in `CliToolIO::writeLine()` (these pass through to Symfony Console `OutputInterface::writeln()`). The session already prints `--- Tool Name ---` before each tool — wrap in `<info>` tags.

Context line (`=== cr=default | node=... ===`) should use `<comment>` for visual separation.

### 2. Sticky ★ markers for new tools

Currently, ★ markers on newly available tools disappear after one menu render. Change: keep markers until the **next context change** (i.e. until a tool returns a non-null `ToolContext`). Track `$lastContextChangeToolSet` instead of `$previousToolSet`.

---

## New Tools

### Document Tree Tool

**Class**: `DocumentTreeTool`
**Location**: `Tool/Node/DocumentTreeTool.php`
**Requires**: `ContentSubgraphInterface $subgraph`, `NodeAggregateId $node`
**Available when**: CR + workspace + DSP + node are set

**Behaviour**:
- Uses `$subgraph->findSubtree($node, FindSubtreeFilter::create(maximumLevels: 3))` to get a tree
- Renders an indented tree with node type + name + aggregateId per line
- Example output (leading with URI path if available, then node name, ID, type):
  ```
  └─ /        (site) 9f3c8992-... Sandstorm.Website:Document.StartPage
     ├─ /about (about) abc123-... Sandstorm.Website:Document.Page
     │  └─ /about/team (team) def456-... Sandstorm.Website:Document.Page
     └─ /blog  (blog) 789abc-... Sandstorm.Website:Document.Page
  ```
- Optionally: after displaying, offer to navigate to a child via `$io->choose()`
- If user picks a child node, return `$context->with('node', $selectedNodeId)`
- If user picks "(stay)", return `null`

**Neos API**:
```php
$subtree = $subgraph->findSubtree($node, FindSubtreeFilter::create(maximumLevels: 3));
// $subtree->node : Node
// $subtree->children : Subtrees (iterable of Subtree)
// $subtree->level : int
```

---

### Node Type Explorer Tool

**Class**: `NodeTypeExplorerTool`
**Location**: `Tool/Entry/NodeTypeExplorerTool.php`
**Requires**: `ContentGraphInterface $contentGraph`
**Available when**: CR + workspace are set

**Behaviour** (two-step interaction):
1. List all node types in use: `$contentGraph->findUsedNodeTypeNames()` → `NodeTypeNames`
2. User picks a node type via `$io->choose()`
3. Find all aggregates of that type: `$contentGraph->findNodeAggregatesByType($selectedType)` → `NodeAggregates`
4. Display table: NodeAggregateId, NodeName, Classification
5. If user picks one, return `$context->with('node', $selectedId)`

**Label**: `"Explore node types"`

**Neos API**:
```php
$nodeTypeNames = $contentGraph->findUsedNodeTypeNames(); // NodeTypeNames (iterable of NodeTypeName)
$aggregates = $contentGraph->findNodeAggregatesByType($nodeTypeName); // NodeAggregates (iterable of NodeAggregate)
```

---

### Find Node by URL Path Tool

**Class**: `FindNodeByPathTool`
**Location**: `Tool/Entry/FindNodeByPathTool.php`
**Requires**: `ContentRepository $cr`, `DimensionSpacePoint $dsp`
**Available when**: CR + DSP are set (workspace not needed — routing projection is workspace-independent)

**Behaviour**:
1. `$io->ask('Enter URL path (e.g. /en/about):')` — prompt user for a URL path
2. Use `DocumentUriPathFinder` to look up the node:
   ```php
   $finder = $cr->projectionState(DocumentUriPathFinder::class);
   $docInfo = $finder->getEnabledBySiteNodeNameUriPathAndDimensionSpacePointHash(
       $siteNodeName, $uriPath, $dsp->hash
   );
   ```
3. Display: URI path, node aggregate ID, node type, disabled status
4. Return `$context->with('node', $docInfo->getNodeAggregateId())`

**Challenge**: Needs `SiteNodeName`. Options:
- (a) Add `SiteNodeName` as a context type (overkill for V2)
- (b) Auto-detect: find the site root by looking up `Neos.Neos:Sites` root aggregate, then its first child. Or use `DocumentUriPathFinder` to get all site names and offer a choice if multiple exist.
- (c) **Chosen approach**: ask user if ambiguous, default to single-site setups. The `DocumentUriPathFinder` stores `sitenodename` in every row — query distinct values.

**Neos API**:
```php
$finder = $cr->projectionState(DocumentUriPathFinder::class);
// Lookup by path:
$docInfo = $finder->getEnabledBySiteNodeNameUriPathAndDimensionSpacePointHash($siteNodeName, $uriPath, $dsp->hash);
// Lookup current node's path:
$docInfo = $finder->getByIdAndDimensionSpacePointHash($nodeId, $dsp->hash);
$docInfo->getUriPath(); // string
$docInfo->getNodeAggregateId(); // NodeAggregateId
$docInfo->getNodeTypeName(); // NodeTypeName
```

---

### Node Routing / URI Path Tool (inspection)

**Class**: `NodeRoutingTool`
**Location**: `Tool/Node/NodeRoutingTool.php`
**Requires**: `ContentRepository $cr`, `NodeAggregateId $node`, `DimensionSpacePoint $dsp`
**Available when**: CR + node + DSP are set

**Behaviour**:
1. Use `DocumentUriPathFinder::getByIdAndDimensionSpacePointHash()` to get the URI path
2. Display: URI path, disabled status, shortcut target if applicable
3. Return `null` (read-only)

**Neos API**:
```php
$finder = $cr->projectionState(DocumentUriPathFinder::class);
$docInfo = $finder->getByIdAndDimensionSpacePointHash($node, $dsp->hash);
$io->writeKeyValue([
    'URI Path' => '/' . $docInfo->getUriPath(),
    'Disabled' => $docInfo->isDisabled() ? 'yes (level ' . $docInfo->getDisableLevel() . ')' : 'no',
    'Shortcut' => $docInfo->isShortcut() ? $docInfo->getShortcutTarget() : '(none)',
]);
```

---

### Child Nodes Tool (simpler than tree)

**Class**: `ChildNodesTool`
**Location**: `Tool/Node/ChildNodesTool.php`
**Requires**: `ContentSubgraphInterface $subgraph`, `NodeAggregateId $node`
**Available when**: CR + workspace + DSP + node are set

**Behaviour**:
1. `$subgraph->findChildNodes($node, FindChildNodesFilter::create())` → `Nodes`
2. Display table: index, NodeAggregateId, NodeTypeName, NodeName
3. Ask user to pick one (or stay)
4. If picked, return `$context->with('node', $selectedId)`

This is simpler than the full tree and serves as the primary navigation tool for drilling into children.

---

## Derived Resolver Additions

The `FindNodeByPathTool` and `NodeRoutingTool` need `DocumentUriPathFinder`. Options:
- (a) Add it as a derived resolver (like ContentRepository/ContentGraph/ContentSubgraph)
- (b) Tools use `ContentRepository::projectionState()` directly, since `ContentRepository` is already a derived resolver

**Recommended**: Option (b) — tools call `$cr->projectionState(DocumentUriPathFinder::class)` internally. No new derived resolver needed. This keeps the resolver list small and the pattern obvious.

---

## Implementation Order

1. **UX fixes**: Symfony formatting + sticky ★ markers (small, visual-only)
2. **ChildNodesTool**: simple, high value, uses existing ContentSubgraph resolver
3. **NodeTypeExplorerTool**: uses existing ContentGraph resolver
4. **NodeRoutingTool**: uses ContentRepository + DocumentUriPathFinder projection
5. **FindNodeByPathTool**: most complex (SiteNodeName discovery), do last
6. **DocumentTreeTool**: tree rendering, nice-to-have after ChildNodesTool exists

---

## Open Questions

- **SiteNodeName resolution**: FindNodeByPathTool needs a SiteNodeName. Best approach TBD — query distinct values from projection? Hardcode first child of Sites root?
- **DocumentUriPathFinder availability**: Not all CR setups have the Neos routing projection. Need to handle `projectionState()` throwing when projection doesn't exist.
- **Tree depth**: How deep should DocumentTreeTool go by default? 3 levels seems reasonable, could make configurable via `$io->ask()`.
