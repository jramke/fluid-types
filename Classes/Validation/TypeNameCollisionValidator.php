<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Validation;

use Jramke\FluidPrimitives\Exception\ConfigurationException;
use Spatie\TypeScriptTransformer\Collections\TransformedCollection;

/**
 * Two types of one name in one TypeScript namespace are two `export type X` declarations, which is
 * invalid TypeScript. spatie never aliases or reports a clashing declaration, so this runs over the
 * final collection of every provider.
 */
final class TypeNameCollisionValidator
{
    public static function assertUnique(TransformedCollection $collection): void
    {
        /** @var array<string, string> $declaredBy */
        $declaredBy = [];

        foreach ($collection as $transformed) {
            $name = $transformed->getName();
            if ($name === null) {
                continue;
            }

            $namespace = implode('.', $transformed->getLocation());
            $key = $namespace . '::' . $name;
            $source = $transformed->getReference()->humanFriendlyName();

            if (!array_key_exists($key, $declaredBy)) {
                $declaredBy[$key] = $source;
                continue;
            }

            throw new ConfigurationException(
                sprintf(
                    'Cannot generate "%s" in the namespace "%s": both "%s" and "%s" produce a type of that ' .
                    'name. Rename one of them or give it another name with #[TypeScript(name: \'...\')] ' .
                    '(or another namespace with #[TypeScript(location: [...])]).',
                    $name,
                    $namespace,
                    $declaredBy[$key],
                    $source,
                ),
                6203418775,
            );
        }
    }
}
