<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Transformers;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Data\TransformationContext;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\Transformed\Untransformable;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;

/**
 * The enum counterpart of spatie's `AttributedClassTransformer`, which skips every enum: transforms a backed enum
 * only when it carries `#[TypeScript]`. The name and location of the attribute are already part of the context.
 */
final class AttributedEnumTransformer extends EnumTransformer
{
    #[\Override]
    public function transform(PhpClassNode $phpClassNode, TransformationContext $context): Transformed|Untransformable
    {
        if ($phpClassNode->getAttributes(TypeScript::class) === []) {
            return Untransformable::create();
        }

        return parent::transform($phpClassNode, $context);
    }
}
