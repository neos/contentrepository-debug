<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\AutoRunToolInterface;

/**
 * @internal Transport-agnostic session loop — construct with a {@see ToolDispatcher}, then call run()
 *           with a {@see ToolIOInterface} implementation to drive a session over any transport.
 */
final class ExploreSession
{
    private static ?ToolContext $exitSentinel = null;

    /**
     * Sentinel value: return this from a tool's execute() to end the session.
     * Recognised by identity (===) in {@see ExploreSession::run}.
     *
     * Usage in an exit tool:
     * ```php
     * public function execute(ToolIOInterface $io): ?ToolContext {
     *     return ExploreSession::exit();
     * }
     * ```
     */
    public static function exit(): ToolContext
    {
        return self::$exitSentinel ??= ToolContext::empty();
    }

    /**
     * @param ?\Closure(ToolContext, ToolIOInterface): void $contextRenderer Called before each menu to display
     *        the current session state. Kept optional so tests and minimal setups can omit it.
     */
    public function __construct(
        private readonly ToolDispatcher $dispatcher,
        private readonly ?\Closure $contextRenderer = null,
    ) {}

    public function run(ToolContext $context, ToolIOInterface $io): void
    {
        // Tool set at last context change — ★ markers stay until context changes again
        /** @var array<class-string, true>|null $baselineToolSet null on first iteration (don't mark anything as new) */
        $baselineToolSet = null;

        while (true) {
            if ($this->contextRenderer !== null) {
                ($this->contextRenderer)($context, $io);
            }

            $available = $this->dispatcher->availableTools($context);

            // Auto-run tools that became newly available (e.g. NodeIdentityTool on node change)
            if ($baselineToolSet !== null) {
                foreach ($available as $tool) {
                    if ($tool instanceof AutoRunToolInterface && !isset($baselineToolSet[$tool::class])) {
                        $io->writeLine('');
                        $io->writeLine('<info>--- ' . $tool->getMenuLabel($context) . ' ---</info>');
                        $this->dispatcher->execute($tool, $context, $io);
                    }
                }
            }

            $choices = [];
            foreach ($available as $i => $tool) {
                $label = $tool->getMenuLabel($context);
                if ($baselineToolSet !== null && !isset($baselineToolSet[$tool::class])) {
                    $label = '★ ' . $label;
                }
                $choices[(string)$i] = $label;
            }

            $selected = $io->choose('Choose a tool', $choices);
            $tool = $available[(int)$selected];

            $io->writeLine('');
            $io->writeLine('<info>--- ' . $tool->getMenuLabel($context) . ' ---</info>');

            $result = $this->dispatcher->execute($tool, $context, $io);

            if ($result === self::exit()) {
                return;
            }

            if ($result !== null) {
                $context = $result;
                // Context changed — snapshot current tool set as new baseline for ★ markers
                $baselineToolSet = [];
                foreach ($available as $t) {
                    $baselineToolSet[$t::class] = true;
                }
            } elseif ($baselineToolSet === null) {
                // First iteration complete — snapshot so we can detect changes on next round
                $baselineToolSet = [];
                foreach ($available as $t) {
                    $baselineToolSet[$t::class] = true;
                }
            }
        }
    }
}
