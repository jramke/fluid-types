<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Fixtures;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript(name: 'Coordinates', location: ['Shop'])]
final readonly class ShopCoordinates implements JsonSerializable
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {}

    /**
     * @return array{lat: float, lng: float}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return ['lat' => $this->latitude, 'lng' => $this->longitude];
    }
}
