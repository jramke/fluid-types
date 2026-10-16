<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Resolver;

use Jramke\FluidPrimitives\Annotations\ClientArgumentAnnotation;
use Jramke\FluidPrimitives\Annotations\RequiredAtRuntimeArgumentAnnotation;
use Jramke\FluidPrimitives\Component\AbstractComponentCollection;
use Jramke\FluidPrimitives\Utility\ClientPropsContextExtractor;
use Jramke\FluidPrimitives\Utility\ComponentUtility;
use Spatie\TypeScriptTransformer\References\CustomReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAlias;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIdentifier;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIndexSignature;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNull;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptObject;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptProperty;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptString;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptUnion;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;

/**
 * Turns one root component into its generated `...HydrationProps` type: the `client` `ui:prop`s
 * plus the `#[ExposeToClient]` context props, each resolved per {@see WireTypeResolver}'s rule.
 */
final readonly class ComponentPropsResolver
{
    /**
     * The result has no `Transformed` when `$viewHelperName` exposes no client-facing prop at all (no `client="{true}"`
     * `ui:prop`, no `#[ExposeToClient]` context method) - just `id`/`ids` is never useful to a
     * consumer calling `mountAll`/`mount`, and would otherwise generate one for every discovered
     * root, including every thin example wrapper that only composes real, already-typed
     * components rather than exposing any data of its own.
     */
    public function resolve(
        WireTypeResolver $wireTypeResolver,
        AbstractComponentCollection $collection,
        string $viewHelperName,
        string $baseName,
        string $namespaceIdentifier,
    ): ResolvedComponentProps {
        $defaultProperties = [
            'id' => new TypeScriptProperty('id', new TypeScriptString()),
            'ids' => new TypeScriptProperty('ids', new TypeScriptObject([
                new TypeScriptProperty(
                    new TypeScriptIndexSignature(new TypeScriptString(), 'key'),
                    new TypeScriptString(),
                ),
            ])),
        ];

        // Keyed by prop name, not a plain list: a #[ExposeToClient] context prop overwrites a
        // same-named ui:prop-derived one below, mirroring ComponentHydrationCollector's own
        // [...$propsMarkedForClientValues, ...$clientPropsFromContext] spread order at runtime -
        // not two colliding properties of the same name.
        $properties = $defaultProperties;
        $bareArrayProps = [];

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

            if ($wireTypeResolver->isBareArrayType($type) && !array_key_exists($name, $contextProps)) {
                $bareArrayProps[] = "{$baseName}.{$name}";
            }

            // A `requiredAtRuntime` ui:prop is still Fluid-optional (a caller need not pass it
            // explicitly - it may come from a default, or another part of the component tree), but
            // PropViewHelper itself throws during rendering if it's ever actually missing by the
            // time the template runs - so by the time this reaches the wire, it's already
            // guaranteed present, unlike a genuinely optional prop.
            $isOptional = !$argumentDefinition->isRequired() && !$this->isRequiredAtRuntime($argumentDefinition);

            $properties[$name] = new TypeScriptProperty(
                $name,
                $this->stripNullWhenOptional($wireTypeResolver->resolveTypeString($type), $isOptional),
                isOptional: $isOptional,
            );
        }

        foreach ($contextProps as $name => $discovered) {
            $type = $wireTypeResolver->resolveMethodReturnType($discovered['method']);
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

        if ($properties === $defaultProperties) {
            // Only the id/ids baseline is here - nothing was ever added by either loop above.
            return new ResolvedComponentProps(null, $bareArrayProps);
        }

        // $baseName can be dot-joined (a tiered or independently-root-nested identity, e.g.
        // "molecules.checkboxGroup") - PascalCase each segment and drop the dots, since a bare
        // dot isn't valid inside a TypeScript identifier the way it is inside this key's own
        // quoted string form just below.
        $typeName = implode('', array_map(ucfirst(...), explode('.', $baseName))) . 'HydrationProps';
        $namespacedKey = "{$namespaceIdentifier}:{$baseName}";

        return new ResolvedComponentProps(
            new Transformed(
                new TypeScriptAlias(
                    new TypeScriptIdentifier($typeName),
                    new TypeScriptObject(array_values($properties)),
                ),
                new CustomReference('component', $namespacedKey),
                [$namespaceIdentifier],
            ),
            $bareArrayProps,
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
}
