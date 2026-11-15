<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Fixtures;

final class TreeNode
{
    public function __construct(
        public string $label,
        public ?TreeNode $parent = null,
    ) {}
}
