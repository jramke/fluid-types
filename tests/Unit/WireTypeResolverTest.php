<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Unit;

use Jramke\FluidPrimitives\Exception\ConfigurationException;
use Jramke\FluidTypes\Resolver\WireTypeResolver;
use Jramke\FluidTypes\Tests\Fixtures\DeliveryChannel;
use Jramke\FluidTypes\Tests\Fixtures\NoPublicState;
use Jramke\FluidTypes\Tests\Fixtures\ShopAddress;
use Jramke\FluidTypes\Tests\Fixtures\ShopCoordinates;
use Jramke\FluidTypes\Tests\Fixtures\ShopOrder;
use Jramke\FluidTypes\Tests\Fixtures\ShopOrderWithDate;
use Jramke\FluidTypes\Tests\Fixtures\ShopSummary;
use Jramke\FluidTypes\Tests\Fixtures\TreeNode;
use Jramke\FluidTypes\Tests\Fixtures\Weekday;
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
    public function resolvesWhatAnObjectRefersToWithoutATag(): void
    {
        $resolver = new WireTypeResolver();
        $resolver->resolveTypeString(ShopOrder::class);
        $resolver->resolveTypeString(ShopSummary::class);

        $resolved = $resolver->getClassTransformeds();
        $classNames = array_keys($resolved);
        sort($classNames);

        // The enum, the JsonSerializable class, the plain class and the interface of ShopOrder, and the class that
        // only a `@return array{...}` shape of ShopSummary names.
        self::assertSame(
            [
                DeliveryChannel::class,
                ShopAddress::class,
                ShopCoordinates::class,
                ShopOrder::class,
                ShopSummary::class,
                Weekday::class,
            ],
            $classNames,
        );
        self::assertSame(
            'type DeliveryChannel = any;',
            $resolved[DeliveryChannel::class]->getNode()->write(new WritingContext([])),
        );
    }

    #[Test]
    public function resolvesAClassThatRefersToItselfOnce(): void
    {
        $resolver = new WireTypeResolver();
        $resolver->resolveTypeString(TreeNode::class);

        self::assertSame([TreeNode::class], array_keys($resolver->getClassTransformeds()));
    }

    /**
     * @return iterable<string, array{0: class-string, 1: string}>
     */
    public static function classesTheClientCannotUse(): iterable
    {
        yield 'internal class inside an object' => [
            ShopOrderWithDate::class,
            'Cannot generate a client type for "DateTimeImmutable": it is not JsonSerializable and has no public ' .
                'properties',
        ];
        yield 'class without public properties' => [
            NoPublicState::class,
            sprintf('Cannot generate a client type for "%s": it is not JsonSerializable', NoPublicState::class),
        ];
    }

    #[Test]
    #[DataProvider('classesTheClientCannotUse')]
    public function stopsForAClassThatWouldReachTheClientAsAnEmptyObject(string $class, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);

        (new WireTypeResolver())->resolveTypeString($class);
    }

    #[Test]
    public function namesTheClassThatReferredToTheOneThatStopsGeneration(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(sprintf('(referenced by "%s")', ShopOrderWithDate::class));

        (new WireTypeResolver())->resolveTypeString(ShopOrderWithDate::class);
    }
}
