<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool;

use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @api Implement this (plus an execute() method with typed params) to create a tool shown in the explore menu.
 */
interface ToolInterface
{
    /**
     * Label shown in the numbered tool menu. May inspect $context to produce a context-sensitive label.
     */
    public function getMenuLabel(ToolContext $context): string;

    // execute() is discovered by reflection in ToolDispatcher.
    // Signature: public function execute(ToolIO $io, [registered-type $param, ...]): ?ToolContext
}
