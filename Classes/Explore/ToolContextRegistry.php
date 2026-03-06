<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

/**
 * @api register() is the public API for adding context dimensions from any package's Package.php.
 */
final class ToolContextRegistry
{
    /** @var array<string, ToolContextTypeDescriptor> keyed by name */
    private array $byName = [];

    /** @var array<string, ToolContextTypeDescriptor> keyed by FQCN */
    private array $byType = [];

    /**
     * @param callable(string): object $fromString
     * @param callable(object): string $toString
     */
    public function register(
        string $name,
        string $type,
        string $alias,
        callable $fromString,
        callable $toString,
    ): void {
        $descriptor = new ToolContextTypeDescriptor($name, $type, $alias, $fromString, $toString);
        $this->byName[$name] = $descriptor;
        $this->byType[$type] = $descriptor;
    }

    /** @internal */
    public function getByName(string $name): ?ToolContextTypeDescriptor
    {
        return $this->byName[$name] ?? null;
    }

    /** @internal */
    public function getByType(string $fqcn): ?ToolContextTypeDescriptor
    {
        return $this->byType[$fqcn] ?? null;
    }

    /** @internal @return iterable<ToolContextTypeDescriptor> */
    public function all(): iterable
    {
        return $this->byName;
    }
}
