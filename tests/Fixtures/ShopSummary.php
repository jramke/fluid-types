<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Fixtures;

use JsonSerializable;

final readonly class ShopSummary implements JsonSerializable
{
    public function __construct(
        private ShopAddress $address,
    ) {}

    /**
     * @return array{address: ShopAddress}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return ['address' => $this->address];
    }
}
