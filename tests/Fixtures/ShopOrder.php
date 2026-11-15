<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Fixtures;

/**
 * No tag anywhere: every class, enum and interface it refers to is reached through its properties.
 */
final class ShopOrder
{
    public function __construct(
        public string $id,
        public ShopAddress $address,
        public Weekday $pickupDay,
        public ShopCoordinates $location,
        public DeliveryChannel $channel,
    ) {}
}
