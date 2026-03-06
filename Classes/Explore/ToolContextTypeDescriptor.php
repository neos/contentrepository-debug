<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

/**
 * @internal Describes one registered context dimension (name, alias, PHP type, serialization).
 */
final class ToolContextTypeDescriptor
{
    /** @param callable(string): object $fromString */
    /** @param callable(object): string $toString */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $alias,
        private readonly mixed $fromString,
        private readonly mixed $toString,
    ) {}

    public function fromString(string $value): object
    {
        return ($this->fromString)($value);
    }

    public function toString(object $value): string
    {
        return ($this->toString)($value);
    }
}
