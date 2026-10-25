<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Unit;

use Jramke\FluidPrimitives\Domain\Dto\ListCollection;
use Jramke\FluidTypes\Resolver\WireTypeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spatie\TypeScriptTransformer\Data\WritingContext;

final class WireTypeResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function ambiguousTypes(): iterable
    {
        yield 'mixed' => ['mixed', 'any'];
        yield 'bare array' => ['array', 'any'];
        yield 'nullable bare array keeps null' => ['?array', 'any | null'];
        yield 'object' => ['object', 'any'];
        yield 'iterable' => ['iterable', 'any'];
        yield 'countable' => ['countable', 'any'];
        yield 'typed list stays precise' => ['string[]', 'string[]'];
    }

    #[Test]
    #[DataProvider('ambiguousTypes')]
    public function ambiguousTypesResolveToAnyNeverUnknown(string $phpType, string $expected): void
    {
        $typeScript = (new WireTypeResolver())
            ->resolveTypeString($phpType)
            ->write(new WritingContext([]));

        self::assertSame($expected, $typeScript);
        self::assertStringNotContainsString('unknown', $typeScript);
    }

    #[Test]
    public function aUnionWithAConvertibleClassIsThatClassAlone(): void
    {
        $resolver = new WireTypeResolver();

        // the array form never reaches the client: it is converted before the component renders
        self::assertSame(
            $resolver->resolveTypeString(ListCollection::class)->write(new WritingContext([])),
            $resolver->resolveTypeString('array|\\' . ListCollection::class)->write(new WritingContext([])),
        );
    }
}
