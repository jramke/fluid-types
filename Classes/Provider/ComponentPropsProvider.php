<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Provider;

use Jramke\FluidPrimitives\Annotations\ClientArgumentAnnotation;
use Jramke\FluidPrimitives\Annotations\RequiredAtRuntimeArgumentAnnotation;
use Jramke\FluidPrimitives\Component\AbstractComponentCollection;
use Jramke\FluidPrimitives\Service\ComponentCollectionService;
use Jramke\FluidPrimitives\Utility\ClientPropsContextExtractor;
use Jramke\FluidPrimitives\Utility\ComponentNameUtility;
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
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNull;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptObject;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptProperty;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptRaw;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptString;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptUnion;
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

                $transformed = $this->resolveComponent($collection, $viewHelperName, $baseName, $namespaceIdentifier);
                $componentTransformeds[] = $transformed;
                $registryReferences[$namespacedKey] = new CustomReference('component', $namespacedKey);
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
        string $namespaceIdentifier,
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

        // getContextClassNameFromViewHelperName() expects the resolved, `/`-separated template
        // path (see its own docblock), not the raw dotted viewHelperName - the same conversion
        // ComponentRootContextFactory does at the real runtime call site. Skipping it here always
        // silently fell back to the generic BaseContext (a dotted string is never a valid PHP
        // class name), so every #[ExposeToClient] context prop went undiscovered.
        $contextClass = ComponentUtility::getContextClassNameFromViewHelperName(
            $collection->resolveTemplateName($viewHelperName),
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

            // A `requiredAtRuntime` ui:prop is still Fluid-optional (a caller need not pass it
            // explicitly - it may come from a default, or another part of the component tree), but
            // PropViewHelper itself throws during rendering if it's ever actually missing by the
            // time the template runs - so by the time this reaches the wire, it's already
            // guaranteed present, unlike a genuinely optional prop.
            $isOptional = !$argumentDefinition->isRequired() && !$this->isRequiredAtRuntime($argumentDefinition);

            $properties[$name] = new TypeScriptProperty(
                $name,
                $this->stripNullWhenOptional($this->wireTypeResolver->resolveTypeString($type), $isOptional),
                isOptional: $isOptional,
            );
        }

        foreach ($contextProps as $name => $discovered) {
            $type = $this->wireTypeResolver->resolveMethodReturnType($discovered['method']);
            $isOptional = $discovered['excludeIfNull'];

            // The key's presence is what `excludeIfNull` actually governs (a null result is
            // dropped from the wire entirely when true, sent verbatim otherwise) - not the type
            // itself, which already reflects the method's own real nullability (from its docblock
            // or native return type) either way. Forcing an extra `| null` here regardless of that
            // would be wrong for a method whose return type is genuinely non-nullable (e.g.
            // ClipboardContext::getTranslations(): array, no `?`).
            $properties[$name] = new TypeScriptProperty(
                $name,
                $this->stripNullWhenOptional($type, $isOptional),
                isOptional: $isOptional,
            );
        }

        // $baseName can be dot-joined (a tiered or independently-root-nested identity, e.g.
        // "molecules.checkboxGroup") - PascalCase each segment and drop the dots, since a bare
        // dot isn't valid inside a TypeScript identifier the way it is inside this key's own
        // quoted string form just below.
        $typeName = implode('', array_map(ucfirst(...), explode('.', $baseName))) . 'HydrationProps';
        $namespacedKey = "{$namespaceIdentifier}:{$baseName}";

        return new Transformed(
            new TypeScriptAlias(new TypeScriptIdentifier($typeName), new TypeScriptObject(array_values($properties))),
            new CustomReference('component', $namespacedKey),
            [$namespaceIdentifier],
        );
    }

    /**
     * A PHP-nullable optional prop (an unset `ui:prop`, or an `excludeIfNull` context method
     * returning `null`) resolves its type with an explicit `| null` member - accurate to what PHP
     * can send, but not what the client actually needs: every one of these props is read directly
     * as a Zag machine constructor prop, and Zag's own machines treat an explicit `null` exactly
     * like an absent/`undefined` prop when defaulting it (e.g. `@zag-js/slider`'s own
     * `thumbSize: prop("thumbSize") || null` - a falsy/nullish check, not `=== undefined`). Once a
     * property is already marked `isOptional` (rendered as `key?: T`, which is `T | undefined`),
     * keeping a redundant `| null` in `T` itself only fights Zag's own prop types (which never
     * declare `| null`) for no behavioral difference - so it's dropped here, not at generation
     * time for a required prop, where `null` is a real, meaningful value the client must still see.
     */
    private function stripNullWhenOptional(TypeScriptNode $type, bool $isOptional): TypeScriptNode
    {
        if (!$isOptional || !$type instanceof TypeScriptUnion) {
            return $type;
        }

        $withoutNull = array_values(array_filter(
            $type->types,
            static fn(TypeScriptNode $member) => !$member instanceof TypeScriptNull,
        ));

        if (count($withoutNull) === count($type->types)) {
            return $type;
        }

        return count($withoutNull) === 1 ? $withoutNull[0] : new TypeScriptUnion($withoutNull);
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

    private function isRequiredAtRuntime(ArgumentDefinition $argumentDefinition): bool
    {
        foreach ($argumentDefinition->getAnnotations() as $annotation) {
            if ($annotation instanceof RequiredAtRuntimeArgumentAnnotation) {
                return true;
            }
        }

        return false;
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
