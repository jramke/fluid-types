<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Unit;

use Jramke\FluidPrimitives\Exception\ConfigurationException;
use Jramke\FluidTypes\Tests\Fixtures\OpeningHours;
use Jramke\FluidTypes\Tests\Fixtures\ShopCoordinates;
use Jramke\FluidTypes\Tests\Fixtures\UndocumentedPayload;
use Jramke\FluidTypes\Transformers\AttributedWireClassTransformer;
use Jramke\FluidTypes\Transformers\WireClassTransformer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Spatie\TypeScriptTransformer\Data\TransformationContext;
use Spatie\TypeScriptTransformer\Data\WritingContext;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\Transformed\Untransformable;
use Spatie\TypeScriptTransformer\Transformers\Transformer;

final class WireClassTransformerTest extends TestCase
{
    #[Test]
    public function jsonSerializeDecidesTheShapeEvenForAClassTaggedWithTheAttribute(): void
    {
        $transformed = $this->transform(new WireClassTransformer(), ShopCoordinates::class);

        self::assertInstanceOf(Transformed::class, $transformed);
        $typeScript = $transformed->getNode()->write(new WritingContext([]));
        self::assertStringContainsString('lat: number', $typeScript);
        self::assertStringNotContainsString('latitude', $typeScript);
    }

    #[Test]
    public function aClassWithoutJsonSerializeTakesItsPublicProperties(): void
    {
        $transformed = $this->transform(new WireClassTransformer(), OpeningHours::class);

        self::assertInstanceOf(Transformed::class, $transformed);
        $typeScript = $transformed->getNode()->write(new WritingContext([]));
        self::assertStringContainsString('day: string', $typeScript);
        self::assertStringContainsString('note: string | null', $typeScript);
        self::assertStringContainsString('slots: string[]', $typeScript);
        self::assertStringNotContainsString('internal', $typeScript);
    }

    #[Test]
    public function jsonSerializeWithoutADocumentedShapeStopsGeneration(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('jsonSerialize() has no documented "@return array{...}" shape');

        $this->transform(new WireClassTransformer(), UndocumentedPayload::class);
    }

    #[Test]
    public function theAttributedVariantTransformsOnlyTaggedClassesUnderTheirNameAndLocation(): void
    {
        $transformer = new AttributedWireClassTransformer();

        $tagged = $this->transform($transformer, ShopCoordinates::class);

        self::assertInstanceOf(Transformed::class, $tagged);
        self::assertSame('Coordinates', $tagged->getName());
        self::assertSame(['Shop'], $tagged->getLocation());

        // Positive control above: a class without the attribute is skipped by the same transformer.
        self::assertInstanceOf(Untransformable::class, $this->transform($transformer, OpeningHours::class));
    }

    /**
     * @param class-string $class
     */
    private function transform(Transformer $transformer, string $class): Transformed|Untransformable
    {
        $node = PhpClassNode::fromReflection(new ReflectionClass($class));

        return $transformer->transform($node, TransformationContext::createFromPhpClass($node));
    }
}
