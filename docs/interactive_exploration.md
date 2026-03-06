# Interactive Exploration Mode (`cr:explore`)

## Goal

A new CLI command `cr:explore` that lets you interactively navigate and inspect a Neos Content Repository — starting from an entry point (node UUID, URI path, etc.), drilling into tools, and always being able to resume or share the exact session state.

Designed to support CLI, web UI, and MCP interfaces from the same core.

---

## Naming

Everything the user can do in a session is a **Tool** (`ToolInterface`). This covers:

- **Entry**: resolve an identifier into context (e.g. enter a node UUID)
- **Navigation**: move to a related entity (e.g. go to parent node)
- **Inspection**: display information about the current context (read-only)

"Inspection" was considered but implies read-only. "Action" has MVC connotations. "Tool" is generic, correct, and aligns with MCP's vocabulary — important since MCP support is a target.

The `Explore` prefix is kept only on `ExploreSession` (the entry concept). All supporting types drop it since they are already scoped by the `Neos\ContentRepository\Debug\Explore` namespace.

---

## Core Principles

- **Resumable**: Every session state maps 1:1 to CLI arguments. A dedicated tool (`ShowResumeCommand`) always appears in the menu and prints the resume invocation — not hardcoded into the loop.
- **Plain-text legible**: Numbered choices, table output. A copy-pasted terminal session must read as a coherent log.
- **Type-safe**: All context values are proper CR value objects. No raw strings internally.
- **Extensible everywhere**: Context types, tools, and transports are all open for extension without touching the core loop.
- **Transport-agnostic core**: Output and interaction are abstracted so the same tool code runs under CLI, web, or MCP.

---

## Context Type Registry

`ToolContextRegistry` — **`@api`: only the `register()` method is public API**. All lookup/iteration methods are `@internal` and used only by `ToolDispatcher`.

Registered globally in `Package.php` (or additional packages' `Package.php`). Each entry describes one kind of context value:

```php
// Package.php
ToolContextRegistry::register(
    name:       'node',
    type:       NodeAggregateId::class,
    alias:      'n',
    fromString: fn(string $s) => NodeAggregateId::fromString($s),
    toString:   fn(NodeAggregateId $v) => $v->value,
);

ToolContextRegistry::register(
    name:       'dsp',
    type:       DimensionSpacePoint::class,
    alias:      'd',
    fromString: fn(string $s) => DimensionSpacePoint::fromArray(/* parse key:val,... */),
    toString:   fn(DimensionSpacePoint $v) => /* key:val,... */,
);

ToolContextRegistry::register(
    name:       'workspace',
    type:       WorkspaceName::class,
    alias:      'ws',
    fromString: fn(string $s) => WorkspaceName::fromString($s),
    toString:   fn(WorkspaceName $v) => $v->value,
);

ToolContextRegistry::register(
    name:       'cr',
    type:       ContentRepositoryId::class,
    alias:      'cr',
    fromString: fn(string $s) => ContentRepositoryId::fromString($s),
    toString:   fn(ContentRepositoryId $v) => $v->value,
);
```

**Any package** can call `ToolContextRegistry::register()` in its own `Package.php` to introduce new context dimensions. No changes to core.

---

## ToolContext — `@api`

A **generic immutable bag**: `string $typeName => object $value`, keyed by the registered type name. Not a fixed DTO. Tool authors read context values from this in `getMenuLabel()`.

```php
final class ToolContext
{
    /** @param array<string, object> $values */
    private function __construct(private readonly array $values) {}

    public static function empty(): self { ... }

    public function with(string $name, object $value): self { ... }    // returns new instance
    public function without(string $name): self { ... }                // returns new instance
    public function get(string $name): ?object { ... }
    public function has(string $name): bool { ... }

    /** @internal Used by ToolDispatcher: look up by registered PHP class. */
    public function getByType(string $fqcn): ?object { ... }
    /** @internal */
    public function hasByType(string $fqcn): bool { ... }
}
```

`ToolContextSerializer` (`@internal`) converts between `ToolContext` and CLI argument strings using the registry's `fromString`/`toString`.

---

## ToolInterface — `@api`

```php
interface ToolInterface
{
    /** Label shown in the numbered tool menu. May be context-sensitive. */
    public function getMenuLabel(ToolContext $context): string;
}
```

That is the **only** required interface method. Availability and invocation are determined by reflection on the `execute()` method (see below). Tools are discovered via DI — no manual registration.

---

## Reflection-Based Dispatch — `@internal`

`ToolDispatcher` determines availability and invokes tools without the tools knowing about the context bag or transport.

### Rules for `execute()` parameters

| Parameter type          | Source              | Availability rule                                                                       |
|-------------------------|---------------------|-----------------------------------------------------------------------------------------|
| Registered context type | `ToolContext`       | Required (`Type $x`) → tool unavailable if missing. Optional (`?Type $x = null`) → always available. |
| `ToolIO`                | Framework-injected  | Always provided.                                                                        |
| `ContentRepository`     | Framework-injected  | Always provided (resolved from context `cr`).                                           |

The dispatcher:
1. Iterates all injected `ToolInterface` implementations (DI-discovered).
2. **On boot / first use**: validates every tool's `execute()` signature. Each parameter type must be either a framework-injected type (`ToolIO`, `ContentRepository`) or a type registered in `ToolContextRegistry`. Any unrecognised type raises an error immediately (not silently ignored at runtime). This catches misconfigured tools — wrong class names, forgotten registry entries — before the session starts.
3. For each tool at runtime: matches required context-typed params against current context.
4. Builds the available-tool list.
5. On user selection: resolves all params (from context + framework), calls `execute()`.

### Example tool signatures — `@api`

```php
// Available any time
public function execute(ToolIO $io, ContentRepository $cr): ?ToolContext

// Available only when a node is in context
public function execute(ToolIO $io, ContentRepository $cr, NodeAggregateId $node): ?ToolContext

// Available when node is set; uses DSP if present, works without it
public function execute(ToolIO $io, ContentRepository $cr, NodeAggregateId $node, ?DimensionSpacePoint $dsp = null): ?ToolContext
```

Return value: `null` = context unchanged (stay); `ToolContext` = updated context. Tools always build on the received context via `$context->with(...)` / `$context->without(...)` — never construct a blank one. This preserves unrelated slots (e.g. `SetNodeByUuid` keeps the existing `dsp` and `workspace` in context).

---

## ToolIO — Transport Abstraction — `@api`

Tools communicate (output and interaction) exclusively through `ToolIO`. This is what allows the same tool code to run under CLI, web, and MCP.

```php
interface ToolIO
{
    // --- Output ---
    public function writeTable(array $headers, array $rows): void;
    public function writeKeyValue(array $pairs): void;      // single record, key→value
    public function writeLine(string $text = ''): void;
    public function writeError(string $message): void;

    // --- Interaction ---

    /**
     * Free-text prompt. Returns the entered string.
     *
     * The optional $autocomplete callback receives the current partial input and
     * returns a list of suggestions. This enables live DB queries — e.g. matching
     * node UUIDs or node type names as the user types.
     *
     * CLI: backed by Symfony Console autocomplete on the Question.
     * Web/MCP: transport may implement as live search / typeahead.
     *
     * @param callable(string $partial): string[] $autocomplete
     */
    public function ask(string $question, ?callable $autocomplete = null): string;

    /**
     * Present a list of labelled choices. Returns the selected key.
     * @param array<string, string> $choices  key => label
     */
    public function choose(string $question, array $choices): string;
}
```

Implementations (`@internal` — tool authors never reference these):
- **`CliToolIO`** — Symfony Console `OutputInterface` + `QuestionHelper`.
- **`WebToolIO`** _(future)_ — streams output via SSE/WebSocket; collects interaction via request/response cycle.
- **`McpToolIO`** _(future)_ — wraps MCP protocol messages.

Tools never reference `InputInterface`, `OutputInterface`, or any transport-specific class directly.

---

## Session Loop — `@internal`

`ExploreSession` is transport-agnostic. `CrExploreCommandController` creates `CliToolIO`, builds initial `ToolContext` from CLI args, and calls `ExploreSession::run()`.

```
loop:
  1. Collect available tools via ToolDispatcher
  2. Show numbered menu (getMenuLabel per tool)
  3. Ask user to pick a number  [ToolIO::choose]
  4. Run selected tool (dispatcher resolves params, calls execute())
  5. If execute() returned a new ToolContext, replace current
  6. go to 1
```

Exit handling is itself a tool:
- **`ExitTool`**: always available as `[0] Exit`.

`ShowResumeCommand` is a regular numbered menu entry. When selected, it prints `./flow cr:explore <serialized context>` and returns null (context unchanged).

---

## Command Interface

```bash
# Start fresh
./flow cr:explore

# Resume / start with known context
./flow cr:explore --node=1234-5678-4232
./flow cr:explore --node=1234-5678-4232 --dsp=language:de --workspace=live
./flow cr:explore --cr=myContentRepository --node=1234-5678-4232
```

CLI options are generated dynamically from the context registry — one `--{name}` option per registered type. No hardcoded option list in the command controller.

---

## V1 Tools

### Entry / navigation (return updated ToolContext, built on existing)

| Tool                   | Signature (context params)                          | Effect                                                              |
|------------------------|-----------------------------------------------------|---------------------------------------------------------------------|
| `SetNodeByUuid`        | _(none)_                                            | `ask()` for UUID → `$context->with('node', ...)` (keeps dsp, ws…)  |
| `ChooseDimension`      | `NodeAggregateId $node`                             | `choose()` from available DSPs → `$context->with('dsp', ...)`      |
| `GoToParentNode`       | `NodeAggregateId $node, DimensionSpacePoint $dsp`   | Subgraph lookup → `$context->with('node', $parent)` (keeps dsp)    |
| `GoBack`               | _(none)_                                            | `$context->without(...)` — removes most-recently-added slot         |

### Inspections / read-only (return null)

| Tool                    | Signature (context params)                              | Output                                       |
|-------------------------|---------------------------------------------------------|----------------------------------------------|
| `NodeIdentity`          | `NodeAggregateId $node`                                 | ID, node type, path, parent aggregate IDs    |
| `NodeProperties`        | `NodeAggregateId $node, DimensionSpacePoint $dsp`       | All properties, JSON pretty-printed          |
| `NodeDimensions`        | `NodeAggregateId $node`                                 | Table: DSP → origin / coverage              |
| `NodeEvents`            | `NodeAggregateId $node`                                 | Last N events for this aggregate             |
| `NodeRouting`           | `NodeAggregateId $node, DimensionSpacePoint $dsp`       | URI path via Neos routing                    |

### Always available

| Tool                   | Effect                                                          |
|------------------------|-----------------------------------------------------------------|
| `ShowResumeCommand`    | Prints `./flow cr:explore <serialized context>` to resume       |
| `ExitTool`             | Exits the session                                               |

---

## Example Session Transcript

```
Resume: ./flow cr:explore --node=1234-5678-4232

=== cr:default | node:1234-5678-4232 ===
[1] Node: identity
[2] Node: dimension variants
[3] Node: events (last 20)
[4] Choose dimension
[5] Set node by UUID
[6] Show resume command
[0] Exit

> 1

ID:     1234-5678-4232
Type:   Neos.NeosIo:Page
Path:   /sites/neosio/en/blog
Parent: abcd-efab-1111

Resume: ./flow cr:explore --node=1234-5678-4232

=== cr:default | node:1234-5678-4232 ===
...

> 4

Available dimension space points:
  [language=de] German
  [language=en] English
  [language=fr] French (specializes language=en)

Choose dimension: language=de

Resume: ./flow cr:explore --node=1234-5678-4232 --dsp=language:de

=== cr:default | node:1234-5678-4232 | dsp:language:de ===
[1] Node: identity
[2] Node: properties
[3] Node: dimension variants
[4] Node: events (last 20)
[5] Node: routing
[6] Go to parent node
[7] Choose dimension
[8] Set node by UUID
[9] Show resume command
[0] Exit

> 5

URI: /de/blog
```

---

## File Structure

```
Classes/
  Package.php                          ← @api  registers built-in context types via ToolContextRegistry::register()

  Explore/
    ToolContextRegistry.php            ← @api (register() only) / @internal (all lookup methods)
    ToolContextTypeDescriptor.php      ← @internal  name, alias, FQCN, fromString, toString
    ToolContext.php                    ← @api  generic immutable bag (name => object); getByType/hasByType are @internal
    ToolContextSerializer.php          ← @internal  context <-> CLI arg strings
    ExploreSession.php                 ← @internal  transport-agnostic session loop

    IO/
      ToolIO.php                       ← @api      interface (output + interaction)
      CliToolIO.php                    ← @internal  Symfony Console implementation

    Tool/
      ToolInterface.php                ← @api      getMenuLabel() only
      ToolDispatcher.php               ← @internal  reflection-based matching + DI collection

      Entry/
        SetNodeByUuidTool.php          ← @internal
        ChooseDimensionTool.php        ← @internal
      Navigation/
        GoToParentNodeTool.php         ← @internal
        GoBackTool.php                 ← @internal
      Node/
        NodeIdentityTool.php           ← @internal
        NodePropertiesTool.php         ← @internal
        NodeDimensionsTool.php         ← @internal
        NodeEventsTool.php             ← @internal
        NodeRoutingTool.php            ← @internal
      Session/
        ShowResumeCommandTool.php      ← @internal
        ExitTool.php                   ← @internal

  Command/
    CrExploreCommandController.php     ← @internal  parse args → ToolContext, create CliToolIO, call ExploreSession
```

**Summary for tool authors** — the only types you need to know:

| Class / Interface       | Role                                                        |
|-------------------------|-------------------------------------------------------------|
| `ToolInterface`         | Implement this to create a tool                             |
| `ToolIO`                | Use this in `execute()` for all output and interaction      |
| `ToolContext`           | Read context values from this in `getMenuLabel()`           |
| `ToolContextRegistry`   | Call `::register()` in your `Package.php` for new context types |

---

## Extensibility

### New context type (e.g. from another package)
1. `ToolContextRegistry::register(...)` in your `Package.php`. Done.

### New tool
1. Implement `ToolInterface` + `execute()` with required types in the signature.
2. Flow auto-wires it into the dispatcher's collection. Done.

### Script-based entry (`cr:debug` integration)
```php
$context = ToolContext::empty()
    ->with('node', NodeAggregateId::fromString('1234-5678'))
    ->with('dsp', DimensionSpacePoint::fromArray(['language' => 'de']));

$session->run($context, new CliToolIO($output, $questionHelper));
```

### Future transports (web / MCP)
Implement `ToolIO` for the target transport. Pass to `ExploreSession::run()`. Zero tool changes.

---

## Open Questions / Deferred

- **`GoBack` mechanics**: snapshot stack vs. "remove last-added slot". Stack handles multi-step tools correctly.
- **Routing from CLI**: Neos routing may assume HTTP context. May need a DB/cache-direct lookup instead.
- **`NodeEvents` stream name**: verify format against live event store data before implementing.
- **Workspace default**: `WorkspaceName::forLive()` when not in context; add workspace-selection tool later.
- **Tool ordering in menu**: registration order, alphabetical, or `#[ToolPriority(n)]` attribute.
