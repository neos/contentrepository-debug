- THIS is a NEOS/FLOW Package!!!
- Run all unit tests: `mise run test` (from this package directory)
- Run a specific test file: `mise run test:unit Tests/Unit/Explore/ToolContextTest.php`
- Raw command if needed: `docker compose exec neos bash -c "cd /app && bin/phpunit -c Build/BuildEssentials/PhpUnit/UnitTests.xml --colors DistributionPackages/Neos.ContentRepository.Debug/Tests/Unit/"`

- TRY TO NOT RUN RAW COMMANDS, but instead update mise tools instead (auditability - and ASK before updating mise tool defs.).

Coding practices:
- Either add a short "why" comment at the doc comment of a class, or add a "@see [classname-with-why-comment] for context" comment accordingly.
- in PHPdocs, if referencing other classes, use {@see [classname]} so that it is auto-clickable in IDEs.
- Mark each class with either @internal [ 1 sentence explanation why] or @api [ 1 sentence explanation why] (ask if unsure).
- Use modern PHP 8.4 syntax.
- Interfaces should end with "Interface" (e.g `ContentGraphProjectionInterface`)
- SMALL, WELL REVIEWABLE, SELF DESCRIBING COMMITS. You can create commits (but let me know), but DO NOT PUSH THEM.
