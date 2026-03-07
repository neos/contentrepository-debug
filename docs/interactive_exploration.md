# Interactive Exploration Mode (`cr:explore`)

## Goal

A CLI command `cr:explore` that lets you interactively navigate and inspect a Neos Content Repository — starting from an entry point (node UUID, workspace, etc.), drilling into tools, and always being able to resume or share the exact session state.

Designed to support CLI, web UI, and MCP interfaces from the same core.

---

## Naming

Everything the user can do in a session is a **Tool** (`ToolInterface`). This covers:

- **Entry**: resolve an identifier into context (e.g. enter a node UUID, choose a workspace)
- **Navigation**: move to a related entity (e.g. go to parent node)
- **Inspection**: display information about the current context (read-only)

"Tool" is generic, correct, and aligns with MCP's vocabulary — important since MCP support is a target.

The `Explore` prefix is kept only on `ExploreSession` (the entry concept). All supporting types drop it since they are already scoped by the `Neos\ContentRepository\Debug\Explore` namespace.

---

## Core Principles

- **Resumable**: Every session state maps 1:1 to CLI arguments. A dedicated tool (`ShowResumeCommand`) always appears in the menu and prints the resume invocation.
- **Plain-text legible**: Numbered choices, table output. A copy-pasted terminal session must read as a coherent log.
- **Type-safe**: All context values are proper CR value objects. No raw strings internally.
- **Extensible everywhere**: Context types, tools, and transports are all open for extension without touching the core loop.
- **Transport-agnostic core**: Output and interaction are abstracted so the same tool code runs under CLI, web, or MCP.

---

## Context Type Registry

`ToolContextRegistry` — **`@api`: only the `register()` method is public API**. All lookup/iteration methods are `@internal` and used only by `ToolDispatcher`.

Registered in `CrCommandController` (currently hardcoded; will move to `Package.php`). Each entry describes one kind of context value:

```php
$registry->register(
    name:       'cr',
    type:       ContentRepositoryId::class,
    alias:      'cr',
    fromString: ContentRepositoryId::fromString(...),
    toString:   fn(ContentRepositoryId $v) => $v->value,
);
$registry->register(
    name:       'node',
    type:       NodeAggregateId::class,
    alias:      'n',
    fromString: NodeAggregateId::fromString(...),
    toString:   fn(NodeAggregateId $v) => (string)$v,
);
$registry->register(
    name:       'workspace',
    type:       WorkspaceName::class,
    alias:      'ws',
    fromString: WorkspaceName::fromString(...),
    toString:   fn(WorkspaceName $v) => (string)$v,
);
$registry->register(
    name:       'dsp',
    type:       DimensionSpacePoint::class,
    alias:      'dsp',
    fromString: DimensionSpacePoint::fromJsonString(...),
    toString:   fn(DimensionSpacePoint $v) => $v->toJson(),
);
```

---

## ToolContext — `@api`

A **generic immutable bag**: `string $name => object $value`. Tool authors read context values in `getMenuLabel()`. Tools set context values via `$context->with(...)` / `$context->withFromString(...)`.

```php
final class ToolContext
{
    public static function empty(): self { ... }
    public static function create(ToolContextRegistry $registry): self { ... }

    public function with(string $name, object $value): self { ... }
    public function withFromString(string $name, string $stringValue): self { ... }
    public function without(string $name): self { ... }
    public function get(string $name): ?object { ... }
    public function has(string $name): bool { ... }

    /** @internal Used by ToolDispatcher */
    public function getByType(string $fqcn): ?object { ... }
    /** @internal */
    public function hasByType(string $fqcn): bool { ... }
}
```

When created via `ToolContext::create($registry)`, the context carries a reference to the registry — enabling `withFromString()` without tools depending on the registry directly.

`ToolContextSerializer` (`@internal`) converts between `ToolContext` and CLI argument strings.

---

## ToolInterface — `@api`

```php
interface ToolInterface
{
    public function getMenuLabel(ToolContext $context): string;
}
```

That is the **only** required interface method. The `execute()` method is discovered by reflection (see Dispatch below). Tools must **never** depend on `ToolContextRegistry` — use `ToolContext::withFromString()` instead.

---

## Reflection-Based Dispatch — `@internal`

`ToolDispatcher` determines availability and invokes tools.

### Rules for `execute()` parameters

| Parameter type            | Source              | Availability rule                                                                         |
|---------------------------|---------------------|-------------------------------------------------------------------------------------------|
| Registered context type   | `ToolContext` bag   | Required (`Type $x`) → tool unavailable if missing. Optional (`?Type $x = null`) → always |
| `ToolIOInterface`         | Framework-injected  | Always provided                                                                           |
| `ToolContext`             | Framework-injected  | Always provided (the full context bag)                                                    |
| Derived type              | Derived resolver    | Resolved lazily from context; null = unavailable                                          |

### Derived resolvers

The dispatcher accepts an optional `$derivedResolvers` map: `array<class-string, Closure(ToolContext): ?object>`. These are **not** a generic services map — they are lazy computations that derive framework types from context values. Currently registered:

| Derived type                 | Resolved from                          | Available when           |
|------------------------------|----------------------------------------|--------------------------|
| `ContentRepository`          | `ContentRepositoryId`                  | Always (cr defaults)     |
| `ContentGraphInterface`      | `ContentRepositoryId` + `WorkspaceName`| CR + workspace set       |
| `ContentSubgraphInterface`   | CR + `WorkspaceName` + `DimensionSpacePoint` | CR + workspace + DSP set |

Other dependencies in tools (e.g. database, external services) use standard Flow `#[Flow\Inject]` property injection — tools are Flow-managed objects.

### Example tool signatures

```php
// Always available (no context params)
public function execute(ToolIOInterface $io, ToolContext $context): ?ToolContext

// Available when CR + workspace set (ContentGraphInterface derived)
public function execute(ToolIOInterface $io, ContentGraphInterface $cg, NodeAggregateId $node): ?ToolContext

// Available when CR + workspace + DSP set (ContentSubgraphInterface derived)
public function execute(ToolIOInterface $io, ContentSubgraphInterface $sg, NodeAggregateId $node): ?ToolContext

// Derived type + context type
public function execute(ToolIOInterface $io, ContentRepository $cr, NodeAggregateId $node): ?ToolContext
```

Return: `null` = context unchanged; `ToolContext` = updated context (built via `$context->with(...)`). Return `ExploreSession::exit()` to end the session.

---

## ToolIOInterface — Transport Abstraction — `@api`

```php
interface ToolIOInterface
{
    public function writeTable(array $headers, array $rows): void;
    public function writeKeyValue(array $pairs): void;
    public function writeLine(string $text = ''): void;
    public function writeError(string $message): void;
    public function ask(string $question, ?callable $autocomplete = null): string;
    public function choose(string $question, array $choices): string;
}
```

Implementations: `CliToolIO` (Symfony Console). Future: web, MCP.

---

## Session Loop — `@internal`

`ExploreSession` is transport-agnostic.

```
loop:
  1. Call contextRenderer (if set) — displays current context state
  2. Collect available tools via ToolDispatcher
  3. Mark newly available tools with ★ prefix
  4. Show numbered menu via ToolIOInterface::choose()
  5. Print tool headline (--- Tool Name ---)
  6. Run selected tool (dispatcher resolves params, calls execute())
  7. If execute() returned ExploreSession::exit(), stop
  8. If execute() returned a new ToolContext, replace current
  9. go to 1
```

The `contextRenderer` is an optional `Closure(ToolContext, ToolIOInterface): void` passed at construction. The controller uses it to print `=== cr=default | node=... ===` before each menu.

---

## Command Interface

```bash
./flow cr:explore
./flow cr:explore --node=1234-5678-4232
./flow cr:explore --node=1234-5678-4232 --workspace=live --dsp='{"language":"en"}'
./flow cr:explore --cr=myContentRepository --node=1234-5678-4232
```

---

## Implemented Tools

### Entry (return updated ToolContext)

| Tool                  | Execute params                                                        | Effect                                                    |
|-----------------------|-----------------------------------------------------------------------|-----------------------------------------------------------|
| `SetNodeByUuidTool`   | `ToolIOInterface $io, ToolContext $context`                           | `ask()` UUID → `$context->withFromString('node', ...)`    |
| `ChooseWorkspaceTool`  | `ToolIOInterface $io, ToolContext $context, ContentRepository $cr`   | `choose()` from CR workspaces → sets workspace            |
| `ChooseDimensionTool`  | `ToolIOInterface $io, ToolContext $context, ContentGraphInterface $cg, NodeAggregateId $node` | `choose()` from node's covered DSPs → sets dsp |

### Inspection (return null)

| Tool                  | Execute params                                                        | Output                                                    |
|-----------------------|-----------------------------------------------------------------------|-----------------------------------------------------------|
| `DiscoverNodeTool`     | `ToolIOInterface $io, ContentRepository $cr, NodeAggregateId $node`  | Table: workspace × node type × covered DSPs               |
| `NodeIdentityTool`     | `ToolIOInterface $io, ContentGraphInterface $cg, NodeAggregateId $node` | ID, type, name, classification, parents                |
| `NodeDimensionsTool`   | `ToolIOInterface $io, ContentGraphInterface $cg, NodeAggregateId $node` | Table: origin DSP → covered DSPs                       |
| `NodePropertiesTool`   | `ToolIOInterface $io, ContentSubgraphInterface $sg, NodeAggregateId $node` | All serialized properties, JSON-formatted           |

### Navigation (return updated ToolContext)

| Tool                  | Execute params                                                        | Effect                                                    |
|-----------------------|-----------------------------------------------------------------------|-----------------------------------------------------------|
| `GoToParentNodeTool`   | `ToolIOInterface $io, ToolContext $context, ContentSubgraphInterface $sg, NodeAggregateId $node` | Sets node to parent |

### Session (always available)

| Tool                   | Effect                                                          |
|------------------------|-----------------------------------------------------------------|
| `ShowResumeCommandTool`| Prints `./flow cr:explore --cr=... --node=... ...`              |
| `ExitTool`             | Exits the session via `ExploreSession::exit()`                  |

---

## File Structure

```
Classes/
  Explore/
    ToolContextRegistry.php
    ToolContextTypeDescriptor.php
    ToolContext.php
    ToolContextSerializer.php
    ToolDispatcher.php
    ExploreSession.php
    ExploreSessionFactory.php         ← auto-discovers tools via ReflectionService (unused currently)

    IO/
      ToolIOInterface.php
      CliToolIO.php

    Tool/
      ToolInterface.php

      Entry/
        SetNodeByUuidTool.php
        ChooseWorkspaceTool.php
        ChooseDimensionTool.php
      Navigation/
        GoToParentNodeTool.php
      Node/
        DiscoverNodeTool.php
        NodeIdentityTool.php
        NodePropertiesTool.php
        NodeDimensionsTool.php
      Session/
        ShowResumeCommandTool.php
        ExitTool.php

  Command/
    CrCommandController.php

Tests/Unit/Explore/
    ToolContextTest.php               (5 tests)
    ToolContextRegistryTest.php       (4 tests)
    ToolDispatcherTest.php            (11 tests)
    ExploreSessionTest.php            (4 tests)
    Tool/Entry/EntryToolsTest.php     (2 tests)
    Tool/Session/SessionToolsTest.php (5 tests)
    Tool/Node/NodeIdentityToolTest.php (3 tests)
```

---

## Planned Tools (V2)

See `interactive_exploration_details.md` for full plan:

- **ChildNodesTool**: navigate into child nodes (requires subgraph + node)
- **DocumentTreeTool**: display subtree as indented tree (requires subgraph + node)
- **NodeTypeExplorerTool**: browse by node type, find aggregates (requires content graph)
- **FindNodeByPathTool**: enter URL path, resolve to node (requires CR + DSP)
- **NodeRoutingTool**: show URI path for current node (requires CR + node + DSP)

---

## Open Questions / Deferred

- **`GoBack` mechanics**: snapshot stack vs. "remove last-added slot". Stack handles multi-step tools correctly.
- **SiteNodeName for routing**: `FindNodeByPathTool` needs SiteNodeName — auto-detect or add as context type?
- **DocumentUriPathFinder availability**: Not all setups have the Neos routing projection. Handle gracefully.
- **NodeEvents**: Need to determine event store stream name format for per-aggregate events.
- **Tool ordering**: currently manual in controller; could use `#[ToolPriority(n)]` attribute or auto-discovery via `ExploreSessionFactory`.
