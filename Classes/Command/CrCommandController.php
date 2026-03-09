<?php

namespace Neos\ContentRepository\Debug\Command;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Debug\ContentRepositoryDebugger;
use Neos\ContentRepository\Debug\Explore\ExploreSession;
use Neos\ContentRepository\Debug\Explore\ExploreSessionFactory;
use Neos\ContentRepository\Debug\Explore\IO\CliToolIO;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Cli\CommandController;

class CrCommandController extends CommandController
{
    private ContentRepositoryDebugger $debugger;

    public function __construct(
        protected readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        private readonly Connection                  $connection,
        private readonly ExploreSessionFactory       $exploreSessionFactory,
    ) {
        parent::__construct();
        $this->debugger = new ContentRepositoryDebugger($this->contentRepositoryRegistry, $this->connection);
    }

    /**
     * Interactive content repository explorer.
     *
     * Supply optional context flags to resume a previous session:
     *   ./flow cr:explore --node=<uuid> --workspace=live --dsp='{"language":"en"}'
     */
    public function exploreCommand(
        string $contentRepository = 'default',
        ?string $node = null,
        ?string $workspace = null,
        ?string $dsp = null,
    ): void {
        $dispatcher = $this->exploreSessionFactory->buildDispatcher();
        $ctx = $this->exploreSessionFactory->buildInitialContext([
            'cr' => $contentRepository,
            'node' => $node,
            'workspace' => $workspace,
            'dsp' => $dsp,
        ]);

        $serializer = $this->exploreSessionFactory->getSerializer();
        $contextRenderer = static function (ToolContext $ctx, ToolIOInterface $io) use ($serializer): void {
            $parts = [];
            foreach ($serializer->serialize($ctx) as $name => $value) {
                $parts[] = "$name=$value";
            }
            $io->writeLine('');
            $io->writeLine('<comment>=== ' . ($parts !== [] ? implode(' | ', $parts) : '(empty context)') . ' ===</comment>');
        };

        $session = new ExploreSession($dispatcher, $contextRenderer);
        $session->run($ctx, new CliToolIO($this->output));
    }

    public function debugCommand(string $debugScript, string $contentRepository = 'default'): void
    {
        $this->outputLine('Debugging script: ' . $debugScript);
        $this->debugger->execScriptFile($debugScript, ContentRepositoryId::fromString($contentRepository));
    }

    public function setupDebugViewsCommand(string $contentRepository = 'default'): void
    {
        $this->outputLine('Setting up Debug Views in ContentRepository ' . $contentRepository);
        $this->debugger->setupDebugViews(ContentRepositoryId::fromString($contentRepository));
    }
}
