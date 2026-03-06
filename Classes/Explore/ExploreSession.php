<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

use Neos\ContentRepository\Debug\Explore\IO\ToolIO;

/**
 * @internal Transport-agnostic session loop — create a ToolIO implementation and call run() to drive a session.
 */
final class ExploreSession
{
    /**
     * Sentinel value returned by a tool's execute() to signal session exit.
     * Use `return ExploreSession::EXIT;` in an exit tool.
     */
    public static ToolContext $EXIT;

    public function __construct(private readonly ToolDispatcher $dispatcher) {}

    public function run(ToolContext $context, ToolIO $io): void
    {
        while (true) {
            $available = $this->dispatcher->availableTools($context);

            $choices = [];
            foreach ($available as $i => $tool) {
                $choices[(string)$i] = $tool->getMenuLabel($context);
            }

            $selected = $io->choose('Choose a tool', $choices);
            $tool = $available[(int)$selected];

            $result = $this->dispatcher->execute($tool, $context, $io);

            if ($result === self::$EXIT) {
                return;
            }

            if ($result !== null) {
                $context = $result;
            }
        }
    }
}

// Initialise the EXIT sentinel as a distinct ToolContext instance.
ExploreSession::$EXIT = ToolContext::empty();
