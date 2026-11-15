<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Fixtures;

final class OpeningHours
{
    /**
     * @param list<string> $slots
     */
    public function __construct(
        public string $day,
        public ?string $note = null,
        public array $slots = [],
        // Private on purpose: the generated type must leave it out.
        // @mago-expect analysis:unused-property
        private string $internal = '',
    ) {}
}
