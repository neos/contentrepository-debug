<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\IO;

/**
 * @api Transport abstraction for tool output and interaction — implement this to add new transports (CLI, web, MCP).
 */
interface ToolIO
{
    public function writeTable(array $headers, array $rows): void;
    public function writeKeyValue(array $pairs): void;
    public function writeLine(string $text = ''): void;
    public function writeError(string $message): void;

    /**
     * @param callable(string $partial): string[] $autocomplete
     */
    public function ask(string $question, ?callable $autocomplete = null): string;

    /**
     * @param array<string, string> $choices key => label
     */
    public function choose(string $question, array $choices): string;
}
