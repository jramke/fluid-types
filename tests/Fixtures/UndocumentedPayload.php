<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Fixtures;

use JsonSerializable;

final readonly class UndocumentedPayload implements JsonSerializable
{
    public function __construct(
        public string $id,
    ) {}

    #[\Override]
    public function jsonSerialize(): array
    {
        return ['id' => $this->id];
    }
}
