<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Transformers;

use Jramke\FluidPrimitives\Exception\ConfigurationException;
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
 * `size`/`first`/`last` output at all). A `JsonSerializable` class without a documented shape fails generation with a
 * message asking for one, rather than silently emitting a type that might not match what is actually sent.
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
        if (!$phpClassNode->implementsInterface(JsonSerializable::class)) {
            return parent::getTypeScriptNode($phpClassNode, $context, $parsedClass);
        }

        $documentedShape = $this->resolveJsonSerializeShape($phpClassNode);

        if ($documentedShape instanceof TypeScriptNode) {
            return $documentedShape;
        }

        throw new ConfigurationException(
            sprintf(
                'Cannot generate a client type for "%s": it implements JsonSerializable, but ' .
                'jsonSerialize() has no documented "@return array{...}" shape describing what it ' .
                'actually returns. Add one - if you don\'t own this class, expose a plain, already-' .
                'flattened value from your own Context or ViewHelper code instead of the raw object.',
                $phpClassNode->getName(),
            ),
            7581211895,
        );
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
