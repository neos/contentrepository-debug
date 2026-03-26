# Tool Selection

## PROBLEMS of current solution:

- not visible which tools exist at all (because only usable tools are shown)
- needs a lot of screen space
- no muscle memory -> numbers always mean something different.


## PONYHOF SCENARIO:


```
What tool do you want to use?

[AN INPUT FIELD WHICH AUTOCOMPLETES HERE; SO I CAN START TYPING "n" and it highlights the ones starting with "n" below]
[THE INPUT FIELD SHOULD AUTOCOMPLETE IF ONLY ONE MATCH IS FOUND (but still in front of cursor)]


*Workspace*       *Dimensions*            *Nodes*               *Events*
wsId (Choose)     dsp (choose)          nId (choose)            seq (set sequence Number + show context around)
simPartialPublish                           nDocTree                nHist (current node hist)  
                                        nContentTree            docHist
*Other*                                n (basic infos)
uriPath                                nProps (properties)
                                        nRefs (references)             
                                        pn (goto parent)        
                                        cn (goto children)

[context = ...]
[HELP LINE:                                         ]

```


- "n" should also contain functuionality of "identity"
- "n" should also contain functionality of "Discover"
- "n" should also contain functuionality of "dims"

You should be able to use the cursors to navigate across the tools -> this then should change the input field as well
The "Context" line should be the current ./flow cr:debug command WITH ALL CONTEXT PARAMS AS NEEDED (dimmed, secondary)

THE HELP LINE should display the full description of the current tool; AND if right aligned which context it needs (differently highlighted in case context is not yet filled).


AFTER A SELECTION IS MADE, ONLY THE THE FOLLOWING TEXT SHOULD BE PART OF THE TERMINAL HISTORY:

```
# "wsId - Choose Workspace"
``` 


## Background / How to impl: 

. laravel/prompts (used standalone + extended)
This is actually framework-agnostic despite the name — it may be used in any command-line PHP project GitHub. For your Neos/Flow use case, you can composer require laravel/prompts and use the function-based API directly. It gives you text inputs, selects, multiselects, autocomplete, confirm, spinners, tables, and now (v0.3.15) a task() primitive with live-updating spinners and scrolling log output.
For custom widgets, this is actually the strongest option: Joe Tannenbaum (now Open Source Lead at Laravel) documented how to extend Prompts into a full TUI framework. You create a model class extending Laravel\Prompts\Prompt for state + keypress handling, and a renderer class extending Renderer for drawing. The rendering logic Jess Archer implemented is smart and reusable, abstracting away the complicated bits — the renderer re-renders the entire screen based on current state and Prompts' diff logic handles only updating changed lines. Joe Tannenbaum

```
Let me put together a practical example of building a custom interactive widget by extending laravel/prompts directly — something that would feel at home in a Neos/Flow CLI command.
I'll build a few things: a basic custom widget showing the pattern, and then something richer like a filterable tree selector (useful for picking node types, for instance).Devised standalone project format for local executionDevised standalone project format for local executionNo composer here, so I'll create a complete downloadable project you can run locally. I'll build three examples going from simple to rich:

TagInput — a multi-tag input widget with inline editing (type, press Enter to add tags, Backspace to remove)
TreeSelect — a collapsible tree browser with arrow-key navigation (think node type picker)
DataTable — a sortable, filterable table with keyboard navigation
Synthesized example request while filtering obsolete optionsSynthesized example request while filtering obsolete optionsHere's the full project. To get it running:
bashunzip custom-prompts-widgets.zip
cd custom-prompts-demo
composer install
php demo.php
The core pattern is dead simple — every custom widget is just two classes:
Widget (extends Prompt) — holds state, binds keys:
phpclass TreeSelect extends Prompt {
    public int $highlighted = 0;
    public array $expanded = [];

    public function __construct(public string $label, public array $tree) {
        // Wire up renderer
        static::$themes['default'][static::class] = TreeSelectRenderer::class;

        // Handle keypresses
        $this->on('key', function ($key) {
            match(true) {
                $key === Key::UP_ARROW   => $this->highlighted--,
                $key === Key::RIGHT_ARROW => $this->expanded[$path] = true,
                $key === Key::ENTER       => $this->submit(),
                // ...
            };
        });
    }

    public function value(): mixed { return $this->selectedValue; }
}
Renderer (extends Renderer) — draws the full screen each cycle, Prompts diffs it:
phpclass TreeSelectRenderer extends Renderer {
    public function __invoke(TreeSelect $prompt): string {
        $this->line($this->cyan('┌ ') . $this->bold($prompt->label));
        foreach ($prompt->visibleRows as $i => $row) {
            $icon = $row['isLeaf'] ? '◦' : '▸';
            $line = str_repeat('  ', $row['depth']) . $icon . ' ' . $row['label'];
            if ($i === $prompt->highlighted) $line = "\e[7m{$line}\e[0m";
            $this->line($this->cyan('│ ') . $line);
        }
        $this->line($this->cyan('└ ') . $this->dim('↑↓ navigate  Enter select'));
        return $this;
    }
}
The three widgets included are: TagInput (multi-tag entry with inline editing), TreeSelect (collapsible tree — perfect for node type picking), and DataTable (sortable/filterable table with row selection). The CHEATSHEET.php has a complete reference of all Key constants, renderer helpers, and ANSI codes. The FlowExample/ folder shows how to wire it into a Neos/Flow CommandController including dynamically building the tree from NodeTypeManager.
```
