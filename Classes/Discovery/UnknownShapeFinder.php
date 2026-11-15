<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Discovery;

use Jramke\FluidTypes\TypeScriptNodes\UnknownJsonShape;
use Spatie\TypeScriptTransformer\Collections\TransformedCollection;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAlias;

/**
 * The classes of a collection that were generated as `any`, for the warning that tells their author how to get a
 * precise type.
 */
final class UnknownShapeFinder
{
    /**
     * @return list<string> sorted class names
     */
    public static function classNames(TransformedCollection $collection): array
    {
        $classNames = [];

        foreach ($collection as $transformed) {
            $node = $transformed->getNode();
            $reference = $transformed->getReference();

            if (
                $node instanceof TypeScriptAlias &&
                $node->type instanceof UnknownJsonShape &&
                $reference instanceof ClassStringReference
            ) {
                $classNames[] = $reference->classString;
            }
        }

        sort($classNames);

        return $classNames;
    }
}
