<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Fixtures;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Tagged, while everything it refers to is not.
 */
#[TypeScript]
final class TaggedShop
{
    public function __construct(
        public ShopAddress $address,
        public Weekday $openOn,
        public DeliveryChannel $channel,
    ) {}
}
