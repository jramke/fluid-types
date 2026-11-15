<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Fixtures;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript(name: 'Delivery', location: ['Shop', 'Types'])]
enum DeliveryMethod: string
{
    case Pickup = 'pickup';
    case Courier = 'courier';
}
