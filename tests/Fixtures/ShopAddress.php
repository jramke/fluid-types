<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Fixtures;

final class ShopAddress
{
    public function __construct(
        public string $street,
        public string $city,
    ) {}
}
