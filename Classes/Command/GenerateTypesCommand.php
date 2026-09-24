<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Command;

use Jramke\FluidPrimitives\Service\PackageResolver;
use Jramke\FluidTypes\Provider\ComponentPropsProvider;
use Jramke\FluidTypes\Provider\FlattenedLocationProvider;
use Spatie\TypeScriptTransformer\Support\Loggers\SymfonyConsoleLogger;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformerProvider;
use Spatie\TypeScriptTransformer\Transformers\AttributedClassTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\ModuleWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates TypeScript types for the current project: every registered Fluid component collection's
 * client-facing props, plus any `#[TypeScript]`-tagged class in a locally-authored extension - one
 * `spatie/typescript-transformer` run, named and scoped generically (not "hydration props") because
 * component props are just one of the things that feed into it.
 */
#[AsCommand(
    name: 'typescript:generate',
    description: 'Generate TypeScript types for this project (component props and any #[TypeScript]-tagged class).',
)]
final class GenerateTypesCommand extends Command
{
    public function __construct(
        private readonly ComponentPropsProvider $componentPropsProvider,
        private readonly PackageResolver $packageResolver,
    ) {
        parent::__construct();
    }

    private const string MANIFEST_FILENAME = 'typescript-transformer-manifest.json';

    protected function configure(): void
    {
        $this->addOption(
            'output',
            null,
            InputOption::VALUE_REQUIRED,
            'Output directory - one file per Fluid namespace, plus a shared one for classes/enums ' .
            'and the HydrationPropsRegistry augmentation',
            (string)getcwd() . '/types.generated',
        )->addOption(
            'check',
            null,
            InputOption::VALUE_NONE,
            'Regenerate into memory and diff against the output directory instead of writing it; exits non-zero on drift',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outputDirectory = (string)$input->getOption('output');

        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, recursive: true) && !is_dir($outputDirectory)) {
            $output->writeln("<error>Unable to create output directory: {$outputDirectory}</error>");

            return Command::FAILURE;
        }

        $taggedClassesProvider = new FlattenedLocationProvider(new TransformerProvider(
            [new AttributedClassTransformer()],
            $this->packageResolver->getLocalClassesDirectories(),
        ));

        // moduleFilename 'index.d.ts', not e.g. 'types.generated.d.ts': spatie's own
        // ResolveRelativePathAction strips a trailing "index" segment from a cross-file import's
        // target path, so a per-namespace file importing a shared class from the root file gets
        // `from '../'` rather than `from '../index'`.
        $config = TypeScriptTransformerConfigFactory::create()
            ->outputDirectory($outputDirectory)
            ->writer(new ModuleWriter(path: null, moduleFilename: 'index.d.ts'))
            ->provider($this->componentPropsProvider, $taggedClassesProvider)
            ->get();

        $transformer = TypeScriptTransformer::create($config, new SymfonyConsoleLogger($output));
        [$transformedCollection] = $transformer->resolveState();

        $writeableFiles = $transformer->resolveFilesAction->execute($transformedCollection);

        if ($input->getOption('check')) {
            return $this->check($writeableFiles, $outputDirectory, $output);
        }

        $transformer->writeFilesAction->execute($writeableFiles);

        foreach ($writeableFiles as $writeableFile) {
            $output->writeln("<info>Generated {$outputDirectory}/{$writeableFile->path}</info>");
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<\Spatie\TypeScriptTransformer\Data\WriteableFile> $writeableFiles
     */
    private function check(array $writeableFiles, string $outputDirectory, OutputInterface $output): int
    {
        $upToDate = true;

        foreach ($writeableFiles as $writeableFile) {
            $path = $outputDirectory . '/' . $writeableFile->path;
            $actual = is_file($path) ? file_get_contents($path) : null;

            if ($actual !== $writeableFile->contents) {
                $upToDate = false;
                $output->writeln("<error>{$path} is out of date.</error>");
            }
        }

        // Orphan detection: a file the previous run generated but this run no longer produces (a
        // component/collection was removed, or its namespace changed) - resolveFilesAction alone
        // can't tell us this, only the manifest writeFilesAction itself maintains can.
        $manifestPath = $outputDirectory . '/' . self::MANIFEST_FILENAME;
        if (is_file($manifestPath)) {
            $oldManifestContent = file_get_contents($manifestPath);
            $decodedManifest = $oldManifestContent === false ? null : json_decode($oldManifestContent, true);
            /** @var array<string, mixed> $oldManifest */
            $oldManifest = is_array($decodedManifest) ? $decodedManifest : [];
            $newPaths = array_map(static fn($file) => $file->path, $writeableFiles);

            foreach (array_diff(array_keys($oldManifest), $newPaths) as $stalePath) {
                $upToDate = false;
                $output->writeln("<error>{$outputDirectory}/{$stalePath} is stale (no longer generated).</error>");
            }
        }

        if (!$upToDate) {
            $output->writeln('<error>Run without --check to regenerate.</error>');

            return Command::FAILURE;
        }

        $output->writeln("<info>{$outputDirectory} is up to date.</info>");

        return Command::SUCCESS;
    }
}
