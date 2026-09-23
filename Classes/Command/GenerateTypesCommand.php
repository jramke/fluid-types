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

    protected function configure(): void
    {
        $this->addOption(
            'output',
            null,
            InputOption::VALUE_REQUIRED,
            'Output file path',
            getcwd() . '/types.generated.d.ts',
        )->addOption(
            'check',
            null,
            InputOption::VALUE_NONE,
            'Regenerate into memory and diff against the output file instead of writing it; exits non-zero on drift',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outputPath = (string)$input->getOption('output');
        $outputDirectory = dirname($outputPath);
        $filename = basename($outputPath);

        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, recursive: true) && !is_dir($outputDirectory)) {
            $output->writeln("<error>Unable to create output directory: {$outputDirectory}</error>");

            return Command::FAILURE;
        }

        $taggedClassesProvider = new FlattenedLocationProvider(new TransformerProvider(
            [new AttributedClassTransformer()],
            $this->packageResolver->getLocalClassesDirectories(),
        ));

        $config = TypeScriptTransformerConfigFactory::create()
            ->outputDirectory($outputDirectory)
            ->writer(new ModuleWriter(path: null, moduleFilename: $filename))
            ->withoutManifest()
            ->provider($this->componentPropsProvider, $taggedClassesProvider)
            ->get();

        $transformer = TypeScriptTransformer::create($config, new SymfonyConsoleLogger($output));
        [$transformedCollection] = $transformer->resolveState();

        $expected = '';
        foreach ($transformer->resolveFilesAction->execute($transformedCollection) as $writeableFile) {
            $expected .= $writeableFile->contents;
        }

        if ($input->getOption('check')) {
            return $this->check($expected, $outputPath, $output);
        }

        file_put_contents($outputPath, $expected);

        $output->writeln("<info>Generated {$outputPath}</info>");

        return Command::SUCCESS;
    }

    private function check(string $expected, string $outputPath, OutputInterface $output): int
    {
        $actual = is_file($outputPath) ? file_get_contents($outputPath) : null;

        if ($actual === $expected) {
            $output->writeln("<info>{$outputPath} is up to date.</info>");

            return Command::SUCCESS;
        }

        $output->writeln("<error>{$outputPath} is out of date - run without --check to regenerate it.</error>");

        return Command::FAILURE;
    }
}
