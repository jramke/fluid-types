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

/**
 * Turns one class, interface or backed enum into the generated type of what it sends to the client. A class that
 * would arrive as `{}` or as the internal shape of PHP (no public properties and not `JsonSerializable`, or an
 * internal class such as `DateTime`) stops generation, the same classes the library refuses to hydrate. An interface
 * has an unknown implementation, so it becomes {@see UnknownJsonShape}.
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
            $reflection->isInterface() && !$reflection->isInternal() => $this->interfaceShape($phpClassNode, $context),
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

    private function interfaceShape(PhpClassNode $phpClassNode, TransformationContext $context): Transformed
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
                        'value instead (a formatted date string for a DateTime), a class with public properties or ' .
                        'one that implements JsonSerializable, or describe the property with ' .
                        '#[LiteralTypeScriptType(\'string\')].',
                        $reflection->getName(),
                    ),
                    3857210946,
                );
            }
        }

        return (new WireClassTransformer())->transform($phpClassNode, $context);
    }
}
