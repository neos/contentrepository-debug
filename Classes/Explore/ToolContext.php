<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

final class ToolContext
{
    /** @param array<string, object> $values */
    private function __construct(private readonly array $values) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function with(string $name, object $value): self
    {
        return new self(array_merge($this->values, [$name => $value]));
    }

    public function without(string $name): self
    {
        $values = $this->values;
        unset($values[$name]);
        return new self($values);
    }

    public function get(string $name): ?object
    {
        return $this->values[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->values[$name]);
    }

    /** @internal Used by ToolDispatcher */
    public function getByType(string $fqcn): ?object
    {
        foreach ($this->values as $value) {
            if ($value instanceof $fqcn) {
                return $value;
            }
        }
        return null;
    }

    /** @internal Used by ToolDispatcher */
    public function hasByType(string $fqcn): bool
    {
        return $this->getByType($fqcn) !== null;
    }
}
