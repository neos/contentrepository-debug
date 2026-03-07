<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool;

/**
 * @api Marker interface for tools that should auto-execute when they become newly available
 *      (e.g. NodeIdentityTool runs automatically when a node is selected).
 *
 * The tool's execute() must be read-only (return null) — any returned ToolContext is ignored during auto-run.
 */
interface AutoRunToolInterface extends ToolInterface
{
}
