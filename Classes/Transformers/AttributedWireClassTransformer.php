<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Transformers;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;

/**
 * {@see WireClassTransformer} for the classes that carry `#[TypeScript]`: what the command generates from the
 * `Classes/` folders of the project without a component prop that uses them. The name and location of the attribute
 * are already part of the transformation context.
 */
final class AttributedWireClassTransformer extends WireClassTransformer
{
    #[\Override]
    protected function shouldTransform(PhpClassNode $phpClassNode): bool
    {
        return $phpClassNode->getAttributes(TypeScript::class) !== [];
    }
}
