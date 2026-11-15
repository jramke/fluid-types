<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Resolver;

use Jramke\FluidPrimitives\Exception\ConfigurationException;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use ReflectionMethod;
use Spatie\TypeScriptTransformer\Actions\TranspilePhpStanTypeToTypeScriptNodeAction;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\PhpNodes\PhpMethodNode;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TypeResolvers\DocTypeResolver;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAny;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptArray;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptBoolean;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptLiteral;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNull;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNumber;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptObject;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptProperty;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptReference;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptString;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptUndefined;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptUnion;
use Spatie\TypeScriptTransformer\Visitor\Visitor;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Resolves a single prop's wire (JSON) type from its PHP type string - the one rule shared by
 * `ui:prop client="{true}"` arguments and `#[ExposeToClient]` context methods alike. Covers the
 * same type vocabulary Fluid's own `StrictArgumentProcessor` recognizes for argument validation
 * (`string`/`int`/`float`/`bool`, `array`/`X[]`, `object`/`iterable`/`countable`, `callable`, union
 * types, and class/interface names) so a `ui:prop` that's valid in Fluid is never something this
 * resolver can't handle at all.
 *
 * An object-typed prop is resolved to a real generated shape (see {@see resolveClassOrEnum}), named
 * exactly like its PHP class; every distinct class is only ever resolved once per run, memoized
 * here so several props/components referencing the same class (e.g. `TypolinkParameter` used by
 * several `link` props) share one generated type rather than duplicating it.
 *
 * The classes and enums a resolved class refers to (a property, a `@return array{...}` shape) are resolved the same
 * way, so an object graph needs no `#[TypeScript]` tag on its nested classes. A class that reaches the client as
 * `{}` or as the internal shape of PHP (no public properties, an internal class such as `DateTime`) stops the command,
 * and an interface, whose implementation is unknown, becomes `any` ({@see ClassShapeTransformer}).
 */
#[Autoconfigure(shared: false)]
final class WireTypeResolver
{
    /** @var array<class-string, Transformed> */
    private array $classTransformeds = [];

    private readonly DocTypeResolver $docTypeResolver;

    private readonly ClassShapeTransformer $classShapeTransformer;

    private readonly TranspilePhpStanTypeToTypeScriptNodeAction $transpilePhpStanTypeToTypeScriptTypeAction;

    public function __construct()
    {
        $this->docTypeResolver = new DocTypeResolver();
        $this->classShapeTransformer = new ClassShapeTransformer();
        $this->transpilePhpStanTypeToTypeScriptTypeAction = new TranspilePhpStanTypeToTypeScriptNodeAction();
    }

    /**
     * Resolves a `#[ExposeToClient]` method's return type, preferring a documented `@return` shape
     * over its bare native return type - a plain `array` return type can't tell a JS array from a
     * JS object (see {@see resolveTypeString}'s own note on that), but a real docblock often
     * already says which, e.g. `SliderContext::getDefaultValue()`'s `@return float[]|null` or
     * `ComboboxContext::getDefaultValue()`'s `@return array<string>|null` - both real, already-
     * written docblocks this recovers real information from, not a guess. Falls back to the native
     * return type when there's no such docblock.
     */
    public function resolveMethodReturnType(ReflectionMethod $method): TypeScriptNode
    {
        $phpMethodNode = new PhpMethodNode($method);
        $parsedMethod = $this->docTypeResolver->method($phpMethodNode);

        if ($parsedMethod?->returnType instanceof TypeNode) {
            $type = $this->resolveDocblockType($parsedMethod->returnType, $phpMethodNode->getDeclaringClass());
            // spatie's docblock transpiler only references the classes it names, so they are resolved here.
            $this->resolveReferencesIn(
                $type,
                $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()',
            );

            return $type;
        }

        $nativeReturnType = $method->getReturnType();

        return $this->resolveTypeString($nativeReturnType === null ? 'mixed' : (string)$nativeReturnType);
    }

    public function resolveTypeString(string $phpType): TypeScriptNode
    {
        $phpType = trim($phpType);

        if ($phpType === '' || strtolower($phpType) === 'mixed') {
            return new TypeScriptAny();
        }

        if (str_starts_with($phpType, '?')) {
            return new TypeScriptUnion([
                $this->resolveTypeString(substr($phpType, offset: 1)),
                new TypeScriptNull(),
            ]);
        }

        if (str_contains($phpType, '|')) {
            return new TypeScriptUnion(array_map($this->resolveTypeString(...), explode('|', $phpType)));
        }

        if (str_ends_with($phpType, '[]')) {
            return new TypeScriptArray([$this->resolveTypeString(substr($phpType, offset: 0, length: -2))]);
        }

        return match (strtolower($phpType)) {
            'string' => new TypeScriptString(),
            'int', 'integer' => new TypeScriptNumber(),
            'float', 'double' => new TypeScriptNumber(),
            'bool', 'boolean' => new TypeScriptBoolean(),
            // None of these carry enough shape info to guess from: a bare `array` could be a JSON
            // array *or* object once encoded (an associative array encodes to a JS object, a list
            // to a JS array - see isBareArrayType()); `object`/`iterable`/`countable` name no
            // specific class at all. `any`, not `unknown`: `unknown` is not assignable to the
            // concrete machine prop types (e.g. `translations`/`positioning` are objects), so a
            // strict `tsc` rejects every registry entry passing them on.
            'array', 'object', 'iterable', 'countable' => new TypeScriptAny(),
            'null' => new TypeScriptNull(),
            'void' => new TypeScriptUndefined(),
            // A callable cannot be sent to the client at all - there is no wire representation for
            // it, so this is a real, clear generation-time error rather than a fallback to any.
            'callable' => throw new ConfigurationException(
                'Cannot generate a client type for a "callable" prop - a callable cannot be sent to the client.',
                5191624609,
            ),
            default => $this->resolveClassOrEnum(ltrim($phpType, characters: '\\')),
        };
    }

    /**
     * True when `$phpType` is a bare (optionally nullable) `array` with no further shape info - the
     * one case {@see resolveTypeString} can't tell a JS array from a JS object for. Exposed so a
     * caller can nudge a `ui:prop` author toward a more precise declaration (e.g. `type="string[]"`)
     * when the prop is actually list-shaped.
     */
    public function isBareArrayType(string $phpType): bool
    {
        return strtolower(ltrim(trim($phpType), characters: '?')) === 'array';
    }

    /**
     * @return array<class-string, Transformed> every class/enum shape resolved so far, to be added
     *   to the same `TransformedCollection` as the components that reference them.
     */
    public function getClassTransformeds(): array
    {
        return $this->classTransformeds;
    }

    /**
     * spatie's own `TranspilePhpStanTypeToTypeScriptNodeAction` maps every bool-ish docblock
     * identifier - `bool`, `true`, and `false` alike - to a plain `TypeScriptBoolean()`, losing a
     * literal `false`/`true` (e.g. `@return array{wordCount: string|false}`, this project's own
     * "no translation" signal). That's real information a caller downstream can't get back - a
     * component that declares its translations `string | false` (never `true`) would otherwise
     * widen to `string | boolean` and no longer satisfy its own prop's stricter type.
     *
     * Recursing through the two structural node kinds these context docblocks actually use here -
     * a top-level union, and an `array{...}` shape's own value types (every `@return array{...}`
     * this project writes) - and special-casing a bare `true`/`false` identifier wherever it turns
     * up in either preserves that literal without reimplementing spatie's full dispatch. Anything
     * structurally deeper (a shape nested inside a shape, say) falls through to spatie's own action
     * unchanged - exactly today's behavior, not a regression, since nothing in this codebase's
     * docblocks currently nests that deep.
     */
    private function resolveDocblockType(TypeNode $type, ?PhpClassNode $phpClassNode): TypeScriptNode
    {
        if ($type instanceof UnionTypeNode) {
            return new TypeScriptUnion(array_map(fn(TypeNode $member) => $this->resolveDocblockType(
                $member,
                $phpClassNode,
            ), $type->types));
        }

        if ($type instanceof IdentifierTypeNode && ($type->name === 'true' || $type->name === 'false')) {
            return new TypeScriptLiteral($type->name === 'true');
        }

        if ($type instanceof ArrayShapeNode || $type instanceof ObjectShapeNode) {
            return $this->resolveDocblockShape($type, $phpClassNode);
        }

        return $this->transpilePhpStanTypeToTypeScriptTypeAction->execute($type, $phpClassNode, []);
    }

    private function resolveDocblockShape(
        ArrayShapeNode|ObjectShapeNode $node,
        ?PhpClassNode $phpClassNode,
    ): TypeScriptNode {
        $properties = [];

        foreach ($node->items as $item) {
            $keyName = $item->keyName;
            $name = match (true) {
                $keyName instanceof IdentifierTypeNode => $keyName->name,
                $keyName instanceof ConstExprStringNode => $keyName->value,
                default => null,
            };

            if ($name === null) {
                // A rarely-used const-fetch-expression key (e.g. `array{Foo::BAR: string}`) - defer
                // to spatie's own action for the whole shape rather than partially reimplementing
                // its const-fetch resolution for one property.
                return $this->transpilePhpStanTypeToTypeScriptTypeAction->execute($node, $phpClassNode, []);
            }

            $properties[] = new TypeScriptProperty(
                $name,
                $this->resolveDocblockType($item->valueType, $phpClassNode),
                isOptional: $item->optional,
            );
        }

        return new TypeScriptObject($properties);
    }

    private function resolveClassOrEnum(string $fqcn): TypeScriptNode
    {
        if (!class_exists($fqcn) && !enum_exists($fqcn) && !interface_exists($fqcn)) {
            throw new ConfigurationException(
                sprintf('Cannot resolve unknown type "%s" for a client prop.', $fqcn),
                1964054794,
            );
        }

        $this->ensureResolved($fqcn);

        return TypeScriptReference::referencingPhpClass($fqcn);
    }

    /**
     * @param class-string $fqcn
     */
    private function ensureResolved(string $fqcn): void
    {
        if (array_key_exists($fqcn, $this->classTransformeds)) {
            return;
        }

        // Stored before its references are walked, so a class that refers back to it ends here.
        $transformed = $this->classShapeTransformer->transform($fqcn);
        $this->classTransformeds[$fqcn] = $transformed;

        $this->resolveReferencesOf($transformed);
    }

    /**
     * Resolves every class, enum and interface that the type of `$transformed` refers to, so none of them needs a
     * `#[TypeScript]` tag of its own.
     */
    public function resolveReferencesOf(Transformed $transformed): void
    {
        $owner = $transformed->getReference();

        $this->resolveReferencesIn(
            $transformed->getNode(),
            $owner instanceof ClassStringReference ? $owner->classString : $owner->humanFriendlyName(),
        );
    }

    private function resolveReferencesIn(TypeScriptNode $node, string $referencedBy): void
    {
        Visitor::create()
            ->before(function (TypeScriptReference $typeReference) use ($referencedBy): void {
                $reference = $typeReference->reference;

                if (!$reference instanceof ClassStringReference) {
                    return;
                }

                try {
                    $this->resolveClassOrEnum($reference->classString);
                } catch (ConfigurationException $exception) {
                    throw new ConfigurationException(
                        sprintf('%s (referenced by "%s")', $exception->getMessage(), $referencedBy),
                        (int)$exception->getCode(),
                        $exception,
                    );
                }
            }, [TypeScriptReference::class])
            ->execute($node);
    }
}
