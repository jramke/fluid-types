<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Transformers;

use Jramke\FluidTypes\TypeScriptNodes\UnknownJsonShape;
use JsonSerializable;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Spatie\TypeScriptTransformer\Data\TransformationContext;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\PhpNodes\PhpMethodNode;
use Spatie\TypeScriptTransformer\Transformers\ClassTransformer;
use Spatie\TypeScriptTransformer\TypeResolvers\Data\ParsedClass;
use Spatie\TypeScriptTransformer\TypeResolvers\Data\ParsedMethod;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;

/**
 * Turns any class into the type of its JSON shape: what a `JsonSerializable` class declares in the `@return array{...}`
 * docblock of `jsonSerialize()`, otherwise its public properties. `jsonSerialize()` decides every time, also for a
 * class tagged with `#[TypeScript]`, because it is the one place where a class's real wire shape is guaranteed
 * accurate - its properties are not (e.g. `ListCollection`'s own constructor properties don't match its computed
 * `size`/`first`/`last` output at all). A `JsonSerializable` class without a documented shape becomes
 * {@see UnknownJsonShape} (`any`): imprecise, but never a type that does not match what is sent.
 */
final class WireClassTransformer extends ClassTransformer
{
    protected function shouldTransform(PhpClassNode $phpClassNode): bool
    {
        return true;
    }

    #[\Override]
    protected function getTypeScriptNode(
        PhpClassNode $phpClassNode,
        TransformationContext $context,
        ?ParsedClass $parsedClass = null,
    ): TypeScriptNode {
        // A type chosen with `#[LiteralTypeScriptType]` or `#[TypeScriptType]` on the class comes first, it is the
        // way to describe a `jsonSerialize()` whose shape no docblock can.
        $typeFromAttribute = $this->resolveTypeByAttribute($phpClassNode);
        if ($typeFromAttribute instanceof TypeScriptNode) {
            return $typeFromAttribute;
        }

        if (!$phpClassNode->implementsInterface(JsonSerializable::class)) {
            return parent::getTypeScriptNode($phpClassNode, $context, $parsedClass);
        }

        $documentedShape = $this->resolveJsonSerializeShape($phpClassNode);

        return $documentedShape ?? new UnknownJsonShape();
    }

    private function resolveJsonSerializeShape(PhpClassNode $phpClassNode): ?TypeScriptNode
    {
        $method = $phpClassNode->getMethod('jsonSerialize');
        if (!$method instanceof PhpMethodNode) {
            return null;
        }

        $parsedMethod = $this->docTypeResolver->method($method);

        if (!$parsedMethod instanceof ParsedMethod || !$parsedMethod->returnType instanceof TypeNode) {
            return null;
        }

        return $this->transpilePhpStanTypeToTypeScriptTypeAction->execute($parsedMethod->returnType, $phpClassNode, []);
    }
}
