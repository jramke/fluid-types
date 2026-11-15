<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Provider;

use Jramke\FluidPrimitives\Service\PackageResolver;
use Jramke\FluidTypes\Resolver\WireTypeResolver;
use Spatie\TypeScriptTransformer\Actions\DiscoverTypesAction;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProvider;

/**
 * The classes, interfaces and backed enums that carry `#[TypeScript]` in the `Classes/` folders of the project's own
 * extensions, plus everything they refer to - resolved by the same rule as the class of a component prop.
 */
final readonly class TaggedClassesProvider implements TransformedProvider
{
    public function __construct(
        private PackageResolver $packageResolver,
        private WireTypeResolver $wireTypeResolver,
    ) {}

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
        foreach ((new DiscoverTypesAction())->execute($directories) as $phpClassNode) {
            if ($phpClassNode->getAttributes(TypeScript::class) === []) {
                continue;
            }

            $this->wireTypeResolver->resolveTypeString($phpClassNode->getName());
        }

        return array_values($this->wireTypeResolver->getClassTransformeds());
    }
}
