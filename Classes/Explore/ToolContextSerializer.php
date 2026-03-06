<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

/**
 * @internal Converts ToolContext to/from CLI argument strings using the registry's callbacks.
 */
final class ToolContextSerializer
{
    public function __construct(private readonly ToolContextRegistry $registry) {}

    /** @return array<string, string> name => stringValue */
    public function serialize(ToolContext $context): array
    {
        $result = [];
        foreach ($this->registry->all() as $descriptor) {
            $value = $context->get($descriptor->name);
            if ($value !== null) {
                $result[$descriptor->name] = $descriptor->toString($value);
            }
        }
        return $result;
    }

    /** @param array<string, string> $strings name => stringValue */
    public function deserialize(array $strings): ToolContext
    {
        $ctx = ToolContext::empty();
        foreach ($strings as $name => $stringValue) {
            $descriptor = $this->registry->getByName($name);
            if ($descriptor !== null) {
                $ctx = $ctx->with($name, $descriptor->fromString($stringValue));
            }
        }
        return $ctx;
    }
}
