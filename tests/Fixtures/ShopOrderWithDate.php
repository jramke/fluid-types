<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Fixtures;

use DateTimeImmutable;

final class ShopOrderWithDate
{
    public function __construct(
        public DateTimeImmutable $placedAt,
    ) {}
}
