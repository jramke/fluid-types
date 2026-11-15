<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Tests\Unit;

use Jramke\FluidTypes\Command\GenerateTypesCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformerProvider;

final class GenerateTypesCommandTest extends TestCase
{
    #[Test]
    public function theDirectoryScanGeneratesOnlyTheClassesAndEnumsTaggedWithTheAttribute(): void
    {
        $provider = new TransformerProvider(GenerateTypesCommand::taggedTransformers(), [__DIR__ . '/../Fixtures']);
        $transformed = $provider->provide();

        $generated = array_map(static fn(Transformed $type): string => implode('.', [
            ...$type->getLocation(),
            $type->getName(),
        ]), $transformed);
        sort($generated);

        // The fixtures also hold an untagged enum, an untagged class and an undocumented JsonSerializable class: the
        // scan skips them without stopping.
        self::assertSame(
            ['Jramke.FluidTypes.Tests.Fixtures.LabelMap', 'Shop.Coordinates', 'Shop.Types.Delivery'],
            $generated,
        );
    }
}
