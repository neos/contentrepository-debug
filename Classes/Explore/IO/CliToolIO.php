<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\IO;

use Neos\Flow\Cli\ConsoleOutput;

use Laravel\Prompts\MultiSelectPrompt;

/**
 * @internal Adapts Flow's {@see ConsoleOutput} to the {@see ToolIOInterface} contract for interactive CLI sessions.
 */

final class CliToolIO implements ToolIOInterface
{
    public function __construct(private readonly ConsoleOutput $console) {}

    public function writeTable(array $headers, array $rows): void
    {
        $this->console->outputTable($rows, $headers);
    }

    public function writeKeyValue(array $pairs): void
    {
        $rows = [];
        foreach ($pairs as $key => $value) {
            $rows[] = ["<b>{$key}</b>", $value];
        }
        $this->console->outputTable($rows);
    }

    public function writeLine(string $text = ''): void
    {
        $this->console->outputLine($text);
    }

    public function writeError(string $message): void
    {
        $this->console->outputLine('<error>' . $message . '</error>');
    }

    public function ask(string $question, ?callable $autocomplete = null): string
    {
        return (string)$this->console->ask($question . ' ');
    }

    public function choose(string $question, array $choices): string
    {
        // Flow's select() may return either the key or the label depending on user input.
        $selected = $this->console->select($question, $choices);
        if (isset($choices[$selected])) {
            return (string)$selected;
        }
        $flipped = array_flip($choices);
        return (string)$flipped[$selected];
    }

    public function chooseMultiple(string $question, array $choices, array $default = []): array
    {
        // laravel/prompts multiselect: arrow keys + space to toggle, returns selected keys.
        $selected = (new MultiSelectPrompt(label: $question, options: $choices, default: $default, scroll: 100))->prompt();
        // Re-sort by position in $choices — laravel/prompts returns keys in toggle order, not options order.
        return array_values(array_intersect(array_keys($choices), $selected));
    }
}
