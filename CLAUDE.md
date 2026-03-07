- THIS is a NEOS/FLOW Package!!!
- Lint (syntax check all PHP files): `mise run lint`
- Run all unit tests: `mise run test`
- Run a specific test file: `mise run test:unit Tests/Unit/Explore/ToolContextTest.php`
- **Always lint before and after editing PHP files.**
- TRY TO NOT RUN RAW COMMANDS — use mise tasks instead. ASK before changing mise task definitions.

Neos/Flow:
- If you need pointers about current Neos/Flow best practices, ASK. The user is a Neos/Flow expert.
- Prefer Flow annotations (`#[Flow\Scope("singleton")]`, `#[Flow\Inject]`) over Objects.yaml.
- DO NOT use `#[Flow\Proxy(false)]` — classes must work with Flow's proxy mechanism.
- On errors, after changing class annotations, run `flow:cache:flush` in the container to clear stale proxies. (You do not need to run this everytime, normally the cache flush works)

Coding practices:
- Either add a short "why" comment at the doc comment of a class, or add a "@see [classname-with-why-comment] for context" comment accordingly.
- in PHPdocs, if referencing other classes, use {@see [classname]} so that it is auto-clickable in IDEs.
- Mark each class with either @internal [ 1 sentence explanation why] or @api [ 1 sentence explanation why] (ask if unsure).
- Use modern PHP 8.4 syntax.
- Interfaces should end with "Interface" (e.g `ContentGraphProjectionInterface`)
- SMALL, WELL REVIEWABLE, SELF DESCRIBING COMMITS. You can create commits (but let me know), but DO NOT PUSH THEM.

Explore tool architecture (see `docs/interactive_exploration.md` for full design):
- Tools implement `ToolInterface` — only `getMenuLabel()` is in the interface; `execute()` is discovered by reflection.
- Tools must NEVER depend on `ToolContextRegistry` — use `ToolContext::withFromString()` instead.
- `ToolDispatcher` resolves `execute()` params: `ToolIOInterface` and `ToolContext` are framework-injected; registered context types come from the context bag; derived types (ContentRepository, ContentGraphInterface, ContentSubgraphInterface) are resolved via lazy closures.
- No generic "services map" on ToolDispatcher — only `derivedResolvers` (typed closures computed from context).
- Other Flow dependencies in tools use standard `#[Flow\Inject]` property injection (tools are Flow-managed objects).
- `ExploreSession::exit()` returns a lazy-init singleton sentinel (NOT a static property — that breaks Flow proxies).
