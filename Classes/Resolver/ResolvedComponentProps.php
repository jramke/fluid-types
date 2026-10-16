<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Resolver;

use Spatie\TypeScriptTransformer\Transformed\Transformed;

/**
 * Result of {@see ComponentPropsResolver::resolve()}.
 */
final readonly class ResolvedComponentProps
{
    /**
     * @param list<string> $bareArrayProps bare `type="array"` ui:props, as "Component.prop"
     */
    public function __construct(
        public ?Transformed $transformed,
        public array $bareArrayProps,
    ) {}
}
