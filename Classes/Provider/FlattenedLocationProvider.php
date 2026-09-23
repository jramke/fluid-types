<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Provider;

use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProvider;

/**
 * Wraps another provider and forces every `Transformed` it produces into the same, flat location -
 * so an independently `#[TypeScript]`-tagged class (which, by default, is organized into its own
 * namespace-derived sub-file) lands in the same single generated file as everything else this
 * command discovers, matching the "one file per run" output model.
 */
final readonly class FlattenedLocationProvider implements TransformedProvider
{
    public function __construct(
        private TransformedProvider $provider,
    ) {}

    public function provide(): array
    {
        $transformed = $this->provider->provide();

        foreach ($transformed as $item) {
            $item->setLocation([]);
        }

        return $transformed;
    }
}
