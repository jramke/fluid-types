<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Unit;

use Jramke\FluidPrimitives\Service\PackageResolver;
use Jramke\FluidTypes\Provider\TaggedClassesProvider;
use Jramke\FluidTypes\Resolver\WireTypeResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spatie\TypeScriptTransformer\Transformed\Transformed;

final class TaggedClassesProviderTest extends TestCase
{
    #[Test]
    public function generatesTheTaggedClassesAndEnumsAndWhatTheyReferTo(): void
    {
        $provider = new TaggedClassesProvider($this->createStub(PackageResolver::class), new WireTypeResolver());
        $transformed = $provider->provideFrom([__DIR__ . '/../Fixtures']);

        $generated = array_map(static fn(Transformed $type): string => implode('.', [
            ...$type->getLocation(),
            $type->getName(),
        ]), $transformed);
        $generated = array_values(array_unique($generated));
        sort($generated);

        // The fixtures also hold an untagged enum, untagged classes and an undocumented JsonSerializable class: the
        // scan skips them. TaggedShop refers to an untagged class, enum and interface, which come along.
        self::assertSame(
            [
                'Jramke.FluidTypes.Tests.Fixtures.DeliveryChannel',
                'Jramke.FluidTypes.Tests.Fixtures.LabelMap',
                'Jramke.FluidTypes.Tests.Fixtures.ShopAddress',
                'Jramke.FluidTypes.Tests.Fixtures.TaggedShop',
                'Jramke.FluidTypes.Tests.Fixtures.Weekday',
                'Shop.Coordinates',
                'Shop.Types.Delivery',
            ],
            $generated,
        );
    }
}
