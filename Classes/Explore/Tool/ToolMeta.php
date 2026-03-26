<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool;

use Neos\Flow\Annotations as Flow;

/**
 * @api Attach to a {@see ToolInterface} implementation to declare its short command name and display group.
 *      When absent, the dispatcher derives both from the class name and namespace automatically.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
#[Flow\Proxy(false)]
final class ToolMeta
{
    public function __construct(
        /** Short name typed by the user in the suggest-as-you-type menu, e.g. "ws" or "props". */
        public readonly string $shortName,
        /** Display group in the tool menu, e.g. "Entry", "Node", "Navigation". */
        public readonly string $group,
    ) {}
}
