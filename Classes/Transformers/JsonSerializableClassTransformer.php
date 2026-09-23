<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Transformers;

use JsonSerializable;
use RuntimeException;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Data\TransformationContext;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\Transformers\ClassTransformer;
use Spatie\TypeScriptTransformer\TypeResolvers\Data\ParsedClass;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;

/**
 * Gives a real generated type to any `JsonSerializable` class that isn't already explicitly
 * `#[TypeScript]`-tagged - the mechanism behind "a class used as a client prop just works, with no
 * manual tagging" (a `Coordinates` DTO, `\TYPO3\CMS\Core\LinkHandling\TypolinkParameter`, ...).
 * `JsonSerializable` is required, at every nesting depth: it's the one explicit, deliberate "yes,
 * this is meant to reach the client" signal a class gives, matching the same requirement
 * `ClientPropValueResolver` already enforces at render time - this only surfaces the same problem
 * earlier, at generation time.
 *
 * The shape comes *only* from what `jsonSerialize()` itself declares (a `@return array{...}`
 * docblock, read the same way any other property/parameter docblock is). Deliberately never falls
 * back to reflecting the class's own properties: `jsonSerialize()` is the one place a class's real
 * wire shape is guaranteed accurate - its properties are not (e.g. `ListCollection`'s own
 * constructor properties don't match its computed `size`/`first`/`last` output at all, and any
 * class could just as easily have unrelated public properties that happen to exist for some other
 * reason). A class without a documented shape fails generation with a message asking for one,
 * rather than silently emitting a type that might not match what's actually sent.
 */
final class JsonSerializableClassTransformer extends ClassTransformer
{
    protected function shouldTransform(PhpClassNode $phpClassNode): bool
    {
        return (
            $phpClassNode->implementsInterface(JsonSerializable::class) &&
            count($phpClassNode->getAttributes(TypeScript::class)) === 0
        );
    }

    protected function getTypeScriptNode(
        PhpClassNode $phpClassNode,
        TransformationContext $context,
        ?ParsedClass $parsedClass = null,
    ): TypeScriptNode {
        $documentedShape = $this->resolveJsonSerializeShape($phpClassNode);

        if ($documentedShape !== null) {
            return $documentedShape;
        }

        throw new RuntimeException(sprintf(
            'Cannot generate a client type for "%s": it implements JsonSerializable, but ' .
            'jsonSerialize() has no documented "@return array{...}" shape describing what it ' .
            'actually returns. Add one - if you don\'t own this class, expose a plain, already-' .
            'flattened value from your own Context or ViewHelper code instead of the raw object.',
            $phpClassNode->getName(),
        ));
    }

    private function resolveJsonSerializeShape(PhpClassNode $phpClassNode): ?TypeScriptNode
    {
        if (!$phpClassNode->hasMethod('jsonSerialize')) {
            return null;
        }

        $parsedMethod = $this->docTypeResolver->method($phpClassNode->getMethod('jsonSerialize'));

        if ($parsedMethod === null || $parsedMethod->returnType === null) {
            return null;
        }

        return $this->transpilePhpStanTypeToTypeScriptTypeAction->execute($parsedMethod->returnType, $phpClassNode, []);
    }
}
