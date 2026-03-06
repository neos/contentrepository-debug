<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

use Neos\ContentRepository\Debug\Explore\IO\ToolIO;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;

/**
 * @internal Reflection-based tool availability matching and invocation — tool authors never reference this.
 */
final class ToolDispatcher
{
    /** @var list<ToolInterface> */
    private readonly array $tools;

    /**
     * @param iterable<ToolInterface> $tools
     * @throws \LogicException if any tool's execute() has an unrecognised parameter type
     */
    public function __construct(
        private readonly ToolContextRegistry $registry,
        iterable $tools,
    ) {
        $validated = [];
        foreach ($tools as $tool) {
            $this->validateTool($tool);
            $validated[] = $tool;
        }
        $this->tools = $validated;
    }

    /** @return list<ToolInterface> */
    public function availableTools(ToolContext $context): array
    {
        $available = [];
        foreach ($this->tools as $tool) {
            if ($this->isAvailable($tool, $context)) {
                $available[] = $tool;
            }
        }
        return $available;
    }

    public function execute(ToolInterface $tool, ToolContext $context, ToolIO $io): ?ToolContext
    {
        $args = $this->resolveArgs($tool, $context, $io);
        return $tool->execute(...$args);
    }

    private function isAvailable(ToolInterface $tool, ToolContext $context): bool
    {
        $method = new \ReflectionMethod($tool, 'execute');
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType) {
                continue;
            }
            $typeName = $type->getName();
            if ($typeName === ToolIO::class) {
                continue;
            }
            // It's a context type — required if not nullable/optional
            if (!$param->isOptional() && !$type->allowsNull()) {
                if (!$context->hasByType($typeName)) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @return list<mixed> */
    private function resolveArgs(ToolInterface $tool, ToolContext $context, ToolIO $io): array
    {
        $method = new \ReflectionMethod($tool, 'execute');
        $args = [];
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType) {
                $args[] = null;
                continue;
            }
            $typeName = $type->getName();
            if ($typeName === ToolIO::class) {
                $args[] = $io;
                continue;
            }
            $args[] = $context->getByType($typeName);
        }
        return $args;
    }

    private function validateTool(ToolInterface $tool): void
    {
        $method = new \ReflectionMethod($tool, 'execute');
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType) {
                continue;
            }
            $typeName = $type->getName();
            if ($typeName === ToolIO::class) {
                continue;
            }
            // Must be a registered context type
            if ($this->registry->getByType($typeName) === null) {
                throw new \LogicException(sprintf(
                    'Tool %s::execute() parameter $%s has type %s which is neither ToolIO nor a registered context type.',
                    $tool::class,
                    $param->getName(),
                    $typeName,
                ));
            }
        }
    }
}
