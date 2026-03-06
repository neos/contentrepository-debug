<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Tests\Unit\Explore;

use Neos\ContentRepository\Debug\Explore\ToolContext;
use PHPUnit\Framework\TestCase;

class ToolContextTest extends TestCase
{
    public function test_empty_context_has_no_values(): void
    {
        $ctx = ToolContext::empty();

        self::assertFalse($ctx->has('node'));
        self::assertNull($ctx->get('node'));
    }

    public function test_with_adds_value_immutably(): void
    {
        $value = new \stdClass();
        $ctx = ToolContext::empty()->with('node', $value);

        self::assertTrue($ctx->has('node'));
        self::assertSame($value, $ctx->get('node'));
        // original is unchanged
        self::assertFalse(ToolContext::empty()->has('node'));
    }

    public function test_without_removes_value_immutably(): void
    {
        $value = new \stdClass();
        $ctx = ToolContext::empty()->with('node', $value)->without('node');

        self::assertFalse($ctx->has('node'));
    }

    public function test_getByType_finds_value_by_class(): void
    {
        $value = new \stdClass();
        $ctx = ToolContext::empty()->with('node', $value);

        self::assertSame($value, $ctx->getByType(\stdClass::class));
        self::assertTrue($ctx->hasByType(\stdClass::class));
    }

    public function test_getByType_returns_null_when_not_present(): void
    {
        $ctx = ToolContext::empty();

        self::assertNull($ctx->getByType(\stdClass::class));
        self::assertFalse($ctx->hasByType(\stdClass::class));
    }
}
