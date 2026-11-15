<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Unit;

use Jramke\FluidPrimitives\Exception\ConfigurationException;
use Jramke\FluidTypes\Tests\Fixtures\LabelMap;
use Jramke\FluidTypes\Tests\Fixtures\OpeningHours;
use Jramke\FluidTypes\Tests\Fixtures\ShopCoordinates;
use Jramke\FluidTypes\Tests\Fixtures\UndocumentedPayload;
use Jramke\FluidTypes\Transformers\WireClassTransformer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Spatie\TypeScriptTransformer\Data\TransformationContext;
use Spatie\TypeScriptTransformer\Data\WritingContext;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\Transformed\Transformed;

final class WireClassTransformerTest extends TestCase
{
    #[Test]
    public function jsonSerializeDecidesTheShapeEvenForAClassTaggedWithTheAttribute(): void
    {
        self::assertSame("type Coordinates = {\nlat: number,\nlng: number,\n};", $this->write(ShopCoordinates::class));
    }

    #[Test]
    public function aClassWithoutJsonSerializeTakesItsPublicProperties(): void
    {
        self::assertSame(
            "type OpeningHours = {\nday: string,\nnote: string | null,\nslots: string[],\n};",
            $this->write(OpeningHours::class),
        );
    }

    #[Test]
    public function aLiteralTypeOnTheClassComesBeforeJsonSerialize(): void
    {
        self::assertSame('type LabelMap = Record<string, string>;', $this->write(LabelMap::class));
    }

    #[Test]
    public function jsonSerializeWithoutADocumentedShapeStopsGeneration(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('jsonSerialize() has no documented "@return array{...}" shape');

        $this->write(UndocumentedPayload::class);
    }

    /**
     * @param class-string $class
     */
    private function write(string $class): string
    {
        $node = PhpClassNode::fromReflection(new ReflectionClass($class));
        $transformed = (new WireClassTransformer())->transform($node, TransformationContext::createFromPhpClass($node));

        self::assertInstanceOf(Transformed::class, $transformed);

        return $transformed->getNode()->write(new WritingContext([]));
    }
}
