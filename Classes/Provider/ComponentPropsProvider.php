<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Provider;

use Jramke\FluidPrimitives\Component\AbstractComponentCollection;
use Jramke\FluidPrimitives\Service\ComponentCollectionService;
use Jramke\FluidPrimitives\Utility\ComponentNameUtility;
use Jramke\FluidTypes\Discovery\RootComponentEnumerator;
use Jramke\FluidTypes\Resolver\ComponentPropsResolver;
use Jramke\FluidTypes\Resolver\WireTypeResolver;
use Spatie\TypeScriptTransformer\References\CustomReference;
use Spatie\TypeScriptTransformer\Support\Loggers\Logger;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TransformedProviders\LoggingTransformedProvider;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProvider;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptRaw;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Builds one generated type per root component (its client-facing `ui:prop`/`#[ExposeToClient]`
 * props, resolved per {@see WireTypeResolver}'s rule), plus the `HydrationPropsRegistry` module
 * augmentation tying each one to its component name - the custom half of this command's output;
 * any independently `#[TypeScript]`-tagged class is handled separately, by spatie's own
 * `TransformerProvider` over each local extension's `Classes/` folder.
 */
final class ComponentPropsProvider implements TransformedProvider, LoggingTransformedProvider
{
    private ?Logger $logger = null;

    public function __construct(
        private readonly ComponentCollectionService $componentCollectionService,
        private readonly RootComponentEnumerator $componentEnumerator,
        private readonly WireTypeResolver $wireTypeResolver,
        private readonly ComponentPropsResolver $componentPropsResolver,
    ) {}

    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @return array<Transformed>
     */
    public function provide(): array
    {
        $componentTransformeds = [];
        $registryReferences = [];
        $bareArrayProps = [];

        foreach ($this->componentCollectionService->discoverCollections() as $collectionClass) {
            $collection = GeneralUtility::makeInstance($collectionClass);

            if (!$collection instanceof AbstractComponentCollection) {
                continue;
            }

            // The same reverse lookup the runtime hydration registry itself uses (see
            // ComponentIdentityResolver::resolve()) - a collection not globally registered under
            // any Fluid namespace can't be hydrated at all, so it contributes nothing here either.
            $namespaceIdentifier =
                $this->componentCollectionService->getViewHelperNamespaceIdentifierByCollectionClassName(
                    $collectionClass,
                );
            if ($namespaceIdentifier === null) {
                continue;
            }

            foreach ($this->componentEnumerator->enumerate($collection) as $viewHelperName) {
                $baseName = $this->componentBaseName($viewHelperName);
                $namespacedKey = "{$namespaceIdentifier}:{$baseName}";

                // Hydration itself is keyed by namespace *and* component name (see
                // HydrationRegistry::add()) - two collections can legitimately register a
                // same-named component with a different shape (a styled wrapper around a primitive
                // it doesn't expose every prop of), so both get their own generated type here too,
                // keyed the same way. A collection registered under the same namespace identifier
                // more than once (unusual, but Fluid allows several delegates per identifier) could
                // still collide on this key - first one found wins in that case, silently, same as
                // Fluid's own resolution order.
                if (array_key_exists($namespacedKey, $registryReferences)) {
                    continue;
                }

                $resolved = $this->componentPropsResolver->resolve(
                    $this->wireTypeResolver,
                    $collection,
                    $viewHelperName,
                    $baseName,
                    $namespaceIdentifier,
                );
                $bareArrayProps = [...$bareArrayProps, ...$resolved->bareArrayProps];
                if (!$resolved->transformed instanceof Transformed) {
                    continue;
                }

                $componentTransformeds[] = $resolved->transformed;
                $registryReferences[$namespacedKey] = new CustomReference('component', $namespacedKey);
            }
        }

        if ($bareArrayProps !== []) {
            $this->logger?->warning(sprintf(
                '%d client props are declared type="array" and generated as "any". If one is a ' .
                'one-dimensional list, declare it as e.g. type="string[]" for a precise type. ' .
                'Run with -v to list them.',
                count($bareArrayProps),
            ));
            foreach ($bareArrayProps as $bareArrayProp) {
                $this->logger?->debug($bareArrayProp . ' is declared type="array"');
            }
        }

        return [
            ...$componentTransformeds,
            ...array_values($this->wireTypeResolver->getClassTransformeds()),
            $this->buildRegistryTransformed($registryReferences),
        ];
    }

    /**
     * `$viewHelperName` here is always one `$componentEnumerator->enumerate()` already classified
     * as root, so `isDeclaredRoot: true` always holds - never a subcomponent's own name.
     */
    private function componentBaseName(string $viewHelperName): string
    {
        return ComponentNameUtility::getComponentBaseNameFromViewHelperName($viewHelperName, isDeclaredRoot: true);
    }

    /**
     * @param array<string, CustomReference> $registryReferences
     */
    private function buildRegistryTransformed(array $registryReferences): Transformed
    {
        $lines = [];
        $references = [];

        foreach ($registryReferences as $namespacedKey => $reference) {
            // Quoted: "ui:select" isn't a valid bare TS property/identifier name (the colon isn't
            // legal in one), unlike the old bare-name keys this replaced.
            $lines[] = "        \"{$namespacedKey}\": %{$namespacedKey}%;";
            $references[$namespacedKey] = $reference;
        }

        $body = implode("\n", $lines);

        return new Transformed(
            new TypeScriptRaw(
                "declare module 'fluid-primitives' {\n    interface HydrationPropsRegistry {\n{$body}\n    }\n}\n",
                references: $references,
            ),
            new CustomReference('registry', 'HydrationPropsRegistry'),
            [],
            export: false,
        );
    }
}
