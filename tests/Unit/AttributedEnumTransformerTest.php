<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Unit;

use Jramke\FluidTypes\Tests\Fixtures\DeliveryMethod;
use Jramke\FluidTypes\Tests\Fixtures\Weekday;
use Jramke\FluidTypes\Transformers\AttributedEnumTransformer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Spatie\TypeScriptTransformer\Data\TransformationContext;
use Spatie\TypeScriptTransformer\Data\WritingContext;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\Transformed\Untransformable;

final class AttributedEnumTransformerTest extends TestCase
{
    #[Test]
    public function transformsOnlyEnumsTaggedWithTheAttributeUnderItsNameAndLocation(): void
    {
        $tagged = $this->transform(DeliveryMethod::class);

        self::assertInstanceOf(Transformed::class, $tagged);
        self::assertSame('Delivery', $tagged->getName());
        self::assertSame(['Shop', 'Types'], $tagged->getLocation());
        self::assertSame("type Delivery = 'pickup' | 'courier';", $tagged->getNode()->write(new WritingContext([])));

        // Positive control above: the same enum shape without the attribute is skipped.
        self::assertInstanceOf(Untransformable::class, $this->transform(Weekday::class));
    }

    /**
     * @param class-string $enum
     */
    private function transform(string $enum): Transformed|Untransformable
    {
        $node = PhpClassNode::fromReflection(new ReflectionClass($enum));

        return (new AttributedEnumTransformer())->transform($node, TransformationContext::createFromPhpClass($node));
    }
}
