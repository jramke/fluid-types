<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Resolver;

use Jramke\FluidTypes\Transformers\JsonSerializableClassTransformer;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Spatie\TypeScriptTransformer\Actions\TranspilePhpStanTypeToTypeScriptNodeAction;
use Spatie\TypeScriptTransformer\Data\TransformationContext;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\PhpNodes\PhpMethodNode;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\Transformed\Untransformable;
use Spatie\TypeScriptTransformer\Transformers\AttributedClassTransformer;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeResolvers\DocTypeResolver;
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
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptUnknown;
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
 * Known v1 limitation: this only resolves the *directly referenced* class for an object-typed
 * prop. A nested property inside an auto-synthesized (untagged) class that is itself an untagged
 * object is resolved by spatie's own default property-type handling, not by this rule recursively -
 * fine for the shapes this project currently generates (`ListCollection`, `TypolinkParameter`, both
 * scalar-only), but a real gap for a deeper untagged object graph.
 */
#[Autoconfigure(shared: false)]
final class WireTypeResolver
{
    /** @var array<class-string, Transformed> */
    private array $classTransformeds = [];

    /** @var array<class-string, true> */
    private array $inProgress = [];

    private DocTypeResolver $docTypeResolver;

    private TranspilePhpStanTypeToTypeScriptNodeAction $transpilePhpStanTypeToTypeScriptTypeAction;

    public function __construct()
    {
        $this->docTypeResolver = new DocTypeResolver();
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

        if ($parsedMethod?->returnType !== null) {
            return $this->resolveDocblockType($parsedMethod->returnType, $phpMethodNode->getDeclaringClass());
        }

        $nativeReturnType = $method->getReturnType();

        return $this->resolveTypeString($nativeReturnType === null ? 'mixed' : (string)$nativeReturnType);
    }

    public function resolveTypeString(string $phpType): TypeScriptNode
    {
        $phpType = trim($phpType);

        if ($phpType === '' || strtolower($phpType) === 'mixed') {
            return new TypeScriptUnknown();
        }

        if (str_starts_with($phpType, '?')) {
            return new TypeScriptUnion([
                $this->resolveTypeString(substr($phpType, 1)),
                new TypeScriptNull(),
            ]);
        }

        if (str_contains($phpType, '|')) {
            return new TypeScriptUnion(array_map(
                fn(string $part) => $this->resolveTypeString($part),
                explode('|', $phpType),
            ));
        }

        if (str_ends_with($phpType, '[]')) {
            return new TypeScriptArray([$this->resolveTypeString(substr($phpType, 0, -2))]);
        }

        return match (strtolower($phpType)) {
            'string' => new TypeScriptString(),
            'int', 'integer' => new TypeScriptNumber(),
            'float', 'double' => new TypeScriptNumber(),
            'bool', 'boolean' => new TypeScriptBoolean(),
            // None of these carry enough shape info to guess from: a bare `array` could be a JSON
            // array *or* object once encoded (an associative array encodes to a JS object, a list
            // to a JS array - see isBareArrayType()); `object`/`iterable`/`countable` name no
            // specific class at all. `unknown` makes no claim either way rather than risking false
            // information (e.g. for `positioning`/`translations`, which are actually objects).
            'array', 'object', 'iterable', 'countable' => new TypeScriptUnknown(),
            'null' => new TypeScriptNull(),
            'void' => new TypeScriptUndefined(),
            // A callable cannot be sent to the client at all - there is no wire representation for
            // it, so this is a real, clear generation-time error rather than a fallback to unknown.
            'callable' => throw new RuntimeException(
                'Cannot generate a client type for a "callable" prop - a callable cannot be sent to the client.',
            ),
            default => $this->resolveClassOrEnum(ltrim($phpType, '\\')),
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
        return strtolower(ltrim(trim($phpType), '?')) === 'array';
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
            $name = match ($item->keyName::class) {
                IdentifierTypeNode::class => $item->keyName->name,
                ConstExprStringNode::class => $item->keyName->value,
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
            throw new RuntimeException(sprintf('Cannot resolve unknown type "%s" for a client prop.', $fqcn));
        }

        $this->ensureResolved($fqcn);

        return TypeScriptReference::referencingPhpClass($fqcn);
    }

    private function ensureResolved(string $fqcn): void
    {
        if (isset($this->classTransformeds[$fqcn]) || isset($this->inProgress[$fqcn])) {
            return;
        }

        $this->inProgress[$fqcn] = true;

        $phpClassNode = PhpClassNode::fromReflection(new ReflectionClass($fqcn));
        $context = TransformationContext::createFromPhpClass($phpClassNode);

        $transformed = $phpClassNode->isEnum()
            ? (new EnumTransformer())->transform($phpClassNode, $context)
            : (new AttributedClassTransformer())->transform($phpClassNode, $context);

        if ($transformed instanceof Untransformable && !$phpClassNode->isEnum()) {
            $transformed = (new JsonSerializableClassTransformer())->transform($phpClassNode, $context);
        }

        unset($this->inProgress[$fqcn]);

        if ($transformed instanceof Untransformable) {
            throw new RuntimeException(sprintf(
                'Cannot generate a client type for "%s": it must implement JsonSerializable to be ' .
                'used as a client-facing prop.',
                $fqcn,
            ));
        }

        $transformed->setLocation([]);
        $this->classTransformeds[$fqcn] = $transformed;
    }
}
