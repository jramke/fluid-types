<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Transformers;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Data\TransformationContext;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\Transformed\Untransformable;
use Spatie\TypeScriptTransformer\Transformers\Transformer;

/**
 * Hands a class or enum to the transformer it wraps only when it carries `#[TypeScript]`: what the command generates
 * from the `Classes/` folders of the project without a component prop that uses it. The name and location of the
 * attribute are already part of the transformation context.
 */
final readonly class TaggedOnlyTransformer implements Transformer
{
    public function __construct(
        private Transformer $transformer,
    ) {}

    #[\Override]
    public function transform(PhpClassNode $phpClassNode, TransformationContext $context): Transformed|Untransformable
    {
        if ($phpClassNode->getAttributes(TypeScript::class) === []) {
            return Untransformable::create();
        }

        return $this->transformer->transform($phpClassNode, $context);
    }
}
