<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ReflectionService;

/**
 * @internal Creates a fully-wired {@see ExploreSession} by auto-discovering all {@see ToolInterface} implementations.
 * @Flow\Scope("singleton")
 */
final class ExploreSessionFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly ToolContextRegistry $registry,
    ) {}

    public function create(): ExploreSession
    {
        /** @var ReflectionService $reflectionService */
        $reflectionService = $this->objectManager->get(ReflectionService::class);
        $classNames = $reflectionService->getAllImplementationClassNamesForInterface(ToolInterface::class);

        $tools = [];
        foreach ($classNames as $className) {
            $tools[] = $this->objectManager->get($className);
        }

        return new ExploreSession(new ToolDispatcher($this->registry, $tools));
    }
}
