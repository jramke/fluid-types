<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Provider;

use Jramke\FluidPrimitives\Annotations\ClientArgumentAnnotation;
use Jramke\FluidPrimitives\Component\AbstractComponentCollection;
use Jramke\FluidPrimitives\Service\ComponentCollectionService;
use Jramke\FluidPrimitives\Utility\ClientPropsContextExtractor;
use Jramke\FluidPrimitives\Utility\ComponentUtility;
use Jramke\FluidTypes\Discovery\RootComponentEnumerator;
use Jramke\FluidTypes\Resolver\WireTypeResolver;
use Spatie\TypeScriptTransformer\References\CustomReference;
use Spatie\TypeScriptTransformer\Support\Loggers\Logger;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TransformedProviders\LoggingTransformedProvider;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProvider;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAlias;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIdentifier;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIndexSignature;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptObject;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptProperty;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptRaw;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptString;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;

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

        foreach ($this->componentCollectionService->discoverCollections() as $collectionClass) {
            $collection = GeneralUtility::makeInstance($collectionClass);

            if (!$collection instanceof AbstractComponentCollection) {
                continue;
            }

            foreach ($this->componentEnumerator->enumerate($collection) as $viewHelperName) {
                $baseName = $this->componentBaseName($viewHelperName);

                // Hydration itself is keyed globally by (kebab) component name, not per collection
                // (see getHydrationData()) - a styled "ui" wrapper that imports a primitive's own
                // props wholesale via `ui:useProps` (e.g. docs' Select wrapper) is, for hydration
                // purposes, the *same* component as the primitive it wraps, not a second one. The
                // first collection a name is found in wins; a later collection's same-named
                // component is skipped rather than silently overwriting it.
                if (array_key_exists($baseName, $registryReferences)) {
                    continue;
                }

                $transformed = $this->resolveComponent($collection, $viewHelperName, $baseName);
                $componentTransformeds[] = $transformed;
                $registryReferences[$baseName] = new CustomReference('component', $baseName);
            }
        }

        return [
            ...$componentTransformeds,
            ...array_values($this->wireTypeResolver->getClassTransformeds()),
            $this->buildRegistryTransformed($registryReferences),
        ];
    }

    private function resolveComponent(
        AbstractComponentCollection $collection,
        string $viewHelperName,
        string $baseName,
    ): Transformed {
        // Keyed by prop name, not a plain list: a #[ExposeToClient] context prop overwrites a
        // same-named ui:prop-derived one below, mirroring ComponentHydrationCollector's own
        // [...$propsMarkedForClientValues, ...$clientPropsFromContext] spread order at runtime -
        // not two colliding properties of the same name.
        $properties = [
            'id' => new TypeScriptProperty('id', new TypeScriptString()),
            'ids' => new TypeScriptProperty('ids', new TypeScriptObject([
                new TypeScriptProperty(
                    new TypeScriptIndexSignature(new TypeScriptString(), 'key'),
                    new TypeScriptString(),
                ),
            ])),
        ];

        $contextClass = ComponentUtility::getContextClassNameFromViewHelperName(
            $viewHelperName,
            $collection->getContextNamespaces(),
        );

        // Discovered before the ui:prop loop below purely so that loop can tell whether a given
        // prop name is *going* to be overwritten by a #[ExposeToClient] context prop of the same
        // name (see the precedence comment above) - and if so, skip warning about its own bare
        // "array" type, since that type never actually surfaces in the final result either way.
        $contextProps = ClientPropsContextExtractor::discover($contextClass);

        foreach ($collection
            ->getComponentDefinition($viewHelperName)
            ->getArgumentDefinitions() as $argumentDefinition) {
            if (!$this->isClientArgument($argumentDefinition)) {
                continue;
            }

            $name = $argumentDefinition->getName();
            $type = $argumentDefinition->getType();

            if ($this->wireTypeResolver->isBareArrayType($type) && !array_key_exists($name, $contextProps)) {
                $this->logger?->warning(sprintf(
                    '%s.%s is declared type="array" - if it\'s actually a one-dimensional list, ' .
                    'declare it as e.g. type="string[]" for a precise generated type instead of "unknown".',
                    $baseName,
                    $name,
                ));
            }

            $properties[$name] = new TypeScriptProperty(
                $name,
                $this->wireTypeResolver->resolveTypeString($type),
                isOptional: !$argumentDefinition->isRequired(),
            );
        }

        foreach ($contextProps as $name => $discovered) {
            $type = $this->wireTypeResolver->resolveMethodReturnType($discovered['method']);

            // The key's presence is what `excludeIfNull` actually governs (a null result is
            // dropped from the wire entirely when true, sent verbatim otherwise) - not the type
            // itself, which already reflects the method's own real nullability (from its docblock
            // or native return type) either way. Forcing an extra `| null` here regardless of that
            // would be wrong for a method whose return type is genuinely non-nullable (e.g.
            // ClipboardContext::getTranslations(): array, no `?`).
            $properties[$name] = new TypeScriptProperty($name, $type, isOptional: $discovered['excludeIfNull']);
        }

        $typeName = ucfirst($baseName) . 'HydrationProps';

        return new Transformed(
            new TypeScriptAlias(new TypeScriptIdentifier($typeName), new TypeScriptObject(array_values($properties))),
            new CustomReference('component', $baseName),
            [],
        );
    }

    private function isClientArgument(ArgumentDefinition $argumentDefinition): bool
    {
        foreach ($argumentDefinition->getAnnotations() as $annotation) {
            if ($annotation instanceof ClientArgumentAnnotation) {
                return true;
            }
        }

        return false;
    }

    private function componentBaseName(string $viewHelperName): string
    {
        return lcfirst(explode('.', $viewHelperName)[0]);
    }

    /**
     * @param array<string, CustomReference> $registryReferences
     */
    private function buildRegistryTransformed(array $registryReferences): Transformed
    {
        $lines = [];
        $references = [];

        foreach ($registryReferences as $baseName => $reference) {
            $lines[] = "        {$baseName}: %{$baseName}%;";
            $references[$baseName] = $reference;
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
