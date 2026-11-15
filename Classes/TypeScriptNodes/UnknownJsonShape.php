<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\TypeScriptNodes;

use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAny;

/**
 * `any` for a class whose JSON shape the command cannot know (a `JsonSerializable` class without a documented
 * `@return array{...}`, an interface). A node of its own so the command can list these types in a warning.
 */
final class UnknownJsonShape extends TypeScriptAny {}
