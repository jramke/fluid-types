<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Unit;

use Jramke\FluidTypes\Discovery\UnknownShapeFinder;
use Jramke\FluidTypes\Resolver\WireTypeResolver;
use Jramke\FluidTypes\Tests\Fixtures\DeliveryChannel;
use Jramke\FluidTypes\Tests\Fixtures\OpeningHours;
use Jramke\FluidTypes\Tests\Fixtures\ShopOrder;
use Jramke\FluidTypes\Tests\Fixtures\UndocumentedPayload;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spatie\TypeScriptTransformer\Collections\TransformedCollection;

final class UnknownShapeFinderTest extends TestCase
{
    #[Test]
    public function listsTheTypesGeneratedAsAnyAndNoOthers(): void
    {
        $resolver = new WireTypeResolver();
        $resolver->resolveTypeString(ShopOrder::class);
        $resolver->resolveTypeString(UndocumentedPayload::class);
        $resolver->resolveTypeString(OpeningHours::class);

        $collection = new TransformedCollection(array_values($resolver->getClassTransformeds()));

        self::assertSame(
            [DeliveryChannel::class, UndocumentedPayload::class],
            UnknownShapeFinder::classNames($collection),
        );
    }
}
