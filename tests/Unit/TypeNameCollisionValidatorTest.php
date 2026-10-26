<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Unit;

use Jramke\FluidPrimitives\Exception\ConfigurationException;
use Jramke\FluidTypes\Validation\TypeNameCollisionValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spatie\TypeScriptTransformer\Collections\TransformedCollection;
use Spatie\TypeScriptTransformer\References\CustomReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAlias;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIdentifier;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptRaw;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptString;

final class TypeNameCollisionValidatorTest extends TestCase
{
    #[Test]
    public function throwsWhenTwoTypesOfOneNameShareAFile(): void
    {
        $collection = new TransformedCollection([
            $this->type('Link', 'acme', ['Acme', 'Dto']),
            $this->type('Link', 'other', ['Acme', 'Dto']),
        ]);

        try {
            TypeNameCollisionValidator::assertUnique($collection);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('"Link" in the namespace "Acme.Dto"', $exception->getMessage());
            self::assertStringContainsString('custom type::acme', $exception->getMessage());
            self::assertStringContainsString('custom type::other', $exception->getMessage());
        }
    }

    #[Test]
    public function allowsOneNameInDifferentNamespacesAndUnnamedTypesInOne(): void
    {
        $collection = new TransformedCollection([
            $this->type('Link', 'acme', ['Acme', 'Dto']),
            $this->type('Link', 'typo3', ['TYPO3', 'Core']),
            $this->registry('first'),
            $this->registry('second'),
        ]);

        TypeNameCollisionValidator::assertUnique($collection);

        // Positive control: the two registries really are in one file, so only the missing name spares them.
        self::assertSame(
            2,
            count(array_filter(
                $collection->all(),
                static fn(Transformed $transformed): bool => $transformed->getName() === null,
            )),
        );
    }

    /**
     * @param list<string> $location
     */
    private function type(string $name, string $key, array $location): Transformed
    {
        return new Transformed(
            new TypeScriptAlias(new TypeScriptIdentifier($name), new TypeScriptString()),
            new CustomReference('type', $key),
            $location,
        );
    }

    private function registry(string $key): Transformed
    {
        return new Transformed(
            new TypeScriptRaw('declare module "x" {}'),
            new CustomReference('registry', $key),
            [],
            export: false,
        );
    }
}
