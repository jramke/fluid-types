<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Resolver;

use Jramke\FluidPrimitives\Exception\ConfigurationException;
use Jramke\FluidTypes\Transformers\WireClassTransformer;
use Jramke\FluidTypes\TypeScriptNodes\UnknownJsonShape;
use JsonSerializable;
use ReflectionClass;
use ReflectionProperty;
use Spatie\TypeScriptTransformer\Data\TransformationContext;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\References\PhpClassReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\Transformed\Untransformable;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAlias;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIdentifier;
use stdClass;

/**
 * Turns one class, interface or backed enum into the generated type of what it sends to the client. A class that
 * would arrive as `{}` or as the internal shape of PHP (no public properties and not `JsonSerializable`, or an
 * internal class such as `DateTime`) stops generation, the same classes the library refuses to hydrate. An interface
 * and `stdClass` have no shape known before runtime, so they become {@see UnknownJsonShape}.
 */
final readonly class ClassShapeTransformer
{
    /**
     * @param class-string $fqcn
     */
    public function transform(string $fqcn): Transformed
    {
        $reflection = new ReflectionClass($fqcn);
        $phpClassNode = PhpClassNode::fromReflection($reflection);
        $context = TransformationContext::createFromPhpClass($phpClassNode);

        $transformed = match (true) {
            $phpClassNode->isEnum() => (new EnumTransformer())->transform($phpClassNode, $context),
            $this->hasUnknownShape($reflection) => $this->unknownShape($phpClassNode, $context),
            default => $this->classShape($reflection, $phpClassNode, $context),
        };

        if ($transformed instanceof Untransformable) {
            throw new ConfigurationException(
                sprintf(
                    'Cannot generate a client type for "%s": only classes and backed enums can be used as a ' .
                    'client-facing prop.',
                    $fqcn,
                ),
                4738794604,
            );
        }

        return $transformed;
    }

    /**
     * An interface other than an internal one such as `DateTimeInterface` (which is exactly as unknown, unless it is
     * `JsonSerializable`), and `stdClass`, which the library hydrates with whatever properties it has at runtime.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function hasUnknownShape(ReflectionClass $reflection): bool
    {
        if ($reflection->getName() === stdClass::class) {
            return true;
        }

        return (
            $reflection->isInterface() &&
            (!$reflection->isInternal() || $reflection->implementsInterface(JsonSerializable::class))
        );
    }

    private function unknownShape(PhpClassNode $phpClassNode, TransformationContext $context): Transformed
    {
        /** @var array<string> $location */
        $location = $context->nameSpaceSegments;

        return new Transformed(
            new TypeScriptAlias(new TypeScriptIdentifier($context->name), new UnknownJsonShape()),
            new PhpClassReference($phpClassNode),
            $location,
            true,
        );
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function classShape(
        ReflectionClass $reflection,
        PhpClassNode $phpClassNode,
        TransformationContext $context,
    ): Transformed|Untransformable {
        if (!$reflection->implementsInterface(JsonSerializable::class)) {
            $publicProperties = array_filter(
                $reflection->getProperties(ReflectionProperty::IS_PUBLIC),
                static fn(ReflectionProperty $property): bool => !$property->isStatic(),
            );

            if ($reflection->isInternal() || $publicProperties === []) {
                throw new ConfigurationException(
                    sprintf(
                        'Cannot generate a client type for "%s": it is not JsonSerializable and has no public ' .
                        'properties, so the client would receive {} or the internal shape of PHP. Pass a plain ' .
                        'value instead, such as a string property that holds a formatted date, a class with public ' .
                        'properties, or a JsonSerializable class whose jsonSerialize() formats the value.',
                        $reflection->getName(),
                    ),
                    3857210946,
                );
            }
        }

        return (new WireClassTransformer())->transform($phpClassNode, $context);
    }
}
