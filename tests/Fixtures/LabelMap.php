<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Fixtures;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A `jsonSerialize()` whose keys are only known at runtime, so no docblock can describe it.
 */
#[TypeScript]
#[LiteralTypeScriptType('Record<string, string>')]
final readonly class LabelMap implements JsonSerializable
{
    /**
     * @param array<string, string> $labels
     */
    public function __construct(
        private array $labels,
    ) {}

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->labels;
    }
}
