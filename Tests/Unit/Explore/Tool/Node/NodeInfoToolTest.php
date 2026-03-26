<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Tests\Unit\Explore\Tool\Node;

use Neos\ContentRepository\Debug\Explore\Tool\Node\NodeInfoTool;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit-level tests for {@see NodeInfoTool} — label and meta only.
 * Error handling and full output are covered by behavioral tests.
 */
class NodeInfoToolTest extends TestCase
{
    public function test_label(): void
    {
        $tool = new NodeInfoTool();
        self::assertSame('Node info', $tool->getMenuLabel(ToolContext::empty()));
    }
}
