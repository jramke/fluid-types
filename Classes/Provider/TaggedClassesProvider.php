<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Provider;

use Jramke\FluidPrimitives\Service\PackageResolver;
use Jramke\FluidTypes\Resolver\WireTypeResolver;
use Jramke\FluidTypes\Transformers\TaggedOnlyTransformer;
use Jramke\FluidTypes\Transformers\WireClassTransformer;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProvider;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformerProvider;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\Transformers\Transformer;

/**
 * The classes and the backed enums that carry `#[TypeScript]` in the `Classes/` folders of the project's own
 * extensions, plus every class, enum and interface they refer to, resolved by the same rule as the classes of a
 * component prop.
 */
final readonly class TaggedClassesProvider implements TransformedProvider
{
    public function __construct(
        private PackageResolver $packageResolver,
        private WireTypeResolver $wireTypeResolver,
    ) {}

    /**
     * @return list<Transformer>
     */
    public static function transformers(): array
    {
        return [
            new TaggedOnlyTransformer(new WireClassTransformer()),
            new TaggedOnlyTransformer(new EnumTransformer()),
        ];
    }

    /**
     * @return array<Transformed>
     */
    #[\Override]
    public function provide(): array
    {
        return $this->provideFrom($this->packageResolver->getLocalClassesDirectories());
    }

    /**
     * @param array<string> $directories
     *
     * @return array<Transformed>
     */
    public function provideFrom(array $directories): array
    {
        $tagged = (new TransformerProvider(self::transformers(), $directories))->provide();

        foreach ($tagged as $transformed) {
            $this->wireTypeResolver->resolveReferencesOf($transformed);
        }

        return [...$tagged, ...array_values($this->wireTypeResolver->getClassTransformeds())];
    }
}
