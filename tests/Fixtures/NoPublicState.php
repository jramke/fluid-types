<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Fixtures;

final readonly class NoPublicState
{
    public function __construct(
        // @mago-expect analysis:unused-property
        private string $state = '',
    ) {}
}
