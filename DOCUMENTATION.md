# TypeScript

**Generates TypeScript types for the client props of your components and for your own PHP classes.**

## Installation

```bash
composer require --dev jramke/fluid-types
```

The package builds on [spatie/typescript-transformer](https://github.com/spatie/typescript-transformer). Its `#[TypeScript]` attribute and its mapping of property types apply to your own classes, see its documentation for the details.

## Generate the types

Run the command from your project root:

```bash
vendor/bin/typo3 typescript:generate
```

It writes three files into a `types.generated` folder, or into the folder you pass with `--output`:

- `types.d.ts` - declares every type in a global TypeScript namespace, so you use the types without an import.
- `registry.d.ts` - connects the component names to those types. It is what types `mountAll` and `mount`.
- `typescript-transformer-manifest.json` - the hashes of the generated files, which [`--check`](#content-check-the-types-in-ci) compares.

Run it again after you change a client prop, an `#[ExposeToClient]` getter or a `#[TypeScript]` class. Recommended: commit the folder, so your frontend build reads the types without PHP and `--check` has something to compare.

Node.js must be available where you run the command, because it formats the files with Prettier through `npx`.

### Add the types to your tsconfig

Include the files with a glob. A plain `"types.generated"` entry reads as a file name because of the dot, and TypeScript skips the folder:

```json
{
    "include": ["src", "types.generated/*.d.ts"]
}
```

## Type the props of a component

`mountAll` and `mount` read the component name, `namespace:name`, and type `props` from it:

```typescript
import { mountAll } from 'fluid-primitives';
import { Collapsible } from 'fluid-primitives/collapsible';

mountAll('ui:collapsible', ({ props }) => {
    // props is ui.CollapsibleHydrationProps
    return new Collapsible(props).init();
});
```

The generated type holds what the server sends to the client:

```typescript
declare namespace ui {
    export type CollapsibleHydrationProps = {
        id: string;
        ids: { [key: string]: string };
        defaultOpen?: boolean;
        disabled?: boolean;
        collapsedHeight?: string;
        collapsedWidth?: string;
    };
}
```

- `id` and `ids` - on every component.
- every `ui:prop` of the root template with `client="{true}"`, see [Client props](/docs/core-concepts/arguments#content-client-props). A required prop is a required property. A prop with a `default` or `optional="{true}"` is an optional one.
- every getter of the component's context marked with `#[ExposeToClient]`, under its `name`, see [Exposing context to client](/docs/core-concepts/context#content-exposing-context-to-client). A getter replaces a prop of the same name. With `excludeIfNull: true` its property is optional.

A root component gets a type when it has client props or exposed getters. Some props change their shape when the client mounts a component: the `collection` of a Select arrives in `props` as a `ListCollection`, while the generated type describes the JSON the server sends.

The type is named after the component and sits in the namespace of its collection: `ui:storeMap` is `ui.StoreMapHydrationProps`. A dotted name drops the dots, `ui:shop.productCard` is `ui.ShopProductCardHydrationProps`. The library's `primitives:select` and your styled `ui:select` get a type each, `primitives.SelectHydrationProps` and `ui.SelectHydrationProps`.

## Map PHP types to TypeScript types

A `ui:prop` takes the TypeScript type that matches its `type`. A store locator map declares its props like this:

```html
<ui:prop name="center" type="MyVendor\MyExtension\Domain\Dto\Coordinates" client="{true}" />
<ui:prop name="zoom" type="int" default="12" client="{true}" />
<ui:prop name="label" type="string" optional="{true}" client="{true}" />
<ui:prop name="stops" type="string[]" client="{true}" />
```

```typescript
declare namespace ui {
    export type StoreMapHydrationProps = {
        id: string;
        ids: { [key: string]: string };
        center: MyVendor.MyExtension.Domain.Dto.Coordinates;
        zoom?: number;
        label?: string;
        stops: string[];
    };
}
```

| PHP type                               | TypeScript type                                            |
| -------------------------------------- | ---------------------------------------------------------- |
| `string`                               | `string`                                                   |
| `int`, `float`                         | `number`                                                   |
| `bool`                                 | `boolean`                                                  |
| `string[]`, `int[]`                    | `string[]`, `number[]`                                     |
| `array`, `object`, `iterable`, `mixed` | `any`                                                      |
| a class                                | its generated type                                         |
| a backed enum                          | a union of its values                                      |
| `callable`                             | stops the command, a callback cannot be sent to the client |

A nullable type adds `null` (`?string` is `string | null`) and a union type becomes a union (`string|int` is `string | number`). An optional property drops the `null`, because the client reads an absent and a `null` prop the same way: `label?: string` for `?string`.

## Use PHP objects as client props

An object passed as a client prop reaches the client as JSON: a class that implements `JsonSerializable` sends what its `jsonSerialize()` returns, any other class its public properties. A `JsonSerializable` class must carry a `@return array{...}` docblock on `jsonSerialize()`, because the type follows it and the command stops for a class without one:

```php
namespace MyVendor\MyExtension\Domain\Dto;

final readonly class Coordinates implements \JsonSerializable
{
    public function __construct(
        private float $latitude,
        private float $longitude,
    ) {}

    /**
     * @return array{lat: float, lng: float}
     */
    public function jsonSerialize(): array
    {
        return ['lat' => $this->latitude, 'lng' => $this->longitude];
    }
}
```

The command generates the class once, in the namespace of its PHP class, and every prop that refers to it shares the type:

```typescript
declare namespace MyVendor.MyExtension.Domain.Dto {
    export type Coordinates = { lat: number; lng: number };
}
```

`jsonSerialize()` decides the type every time, also for a class tagged with `#[TypeScript]`. A class without `JsonSerializable` gets its public properties as the type.

## Type exposed getters

A getter marked with `#[ExposeToClient]` takes the type of its return value. Describe an array with a `@return` docblock, the same way as `jsonSerialize()` above. The docblock is used in place of the native type:

```php
use Jramke\FluidPrimitives\Attributes\ExposeToClient;

/**
 * @return array{currency: string, amount: float}|null
 */
#[ExposeToClient(excludeIfNull: true)]
public function getDiscount(): ?array
{
    // ...
}
```

This generates `discount?: { currency: string; amount: number }`. A list works the same, `@return float[]|null` generates `number[] | null`.

## Generate types for your own classes

Tag a class or a backed enum with `#[TypeScript]` to generate its type without a component prop that uses it, for example for the JSON an Extbase action returns to [`extbase.get()`](/docs/client-utilities/extbase):

```php
namespace MyVendor\MyExtension\Domain\Dto;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class OpeningHours
{
    public function __construct(
        public string $day,
        public string $opens,
        public string $closes,
        public bool $open24h = false,
    ) {}
}
```

Use it anywhere in your TypeScript, without an import:

```typescript
const monday: MyVendor.MyExtension.Domain.Dto.OpeningHours = {
    day: 'Monday',
    opens: '09:00',
    closes: '17:30',
    open24h: false,
};
```

The type contains the public properties. Describe the items of a list with `@var list<OpeningHours>` on a property or `@param list<OpeningHours> $hours` on the constructor parameter that promotes it.

The type is named like the class and sits in its PHP namespace. Choose both with the attribute:

```php
#[TypeScript(name: 'Shop', location: ['Shop', 'Types'])]
final class StoreLocation
{
    // ...
}
```

This generates `Shop.Types.Shop`. Two types of one name in one namespace stop the command with the names of both, rename one or give it another `location`.

Tag the classes and enums that a type refers to, such as the enum of a property, so the command generates them as well.

A `JsonSerializable` class whose shape no docblock can describe takes a type from `#[LiteralTypeScriptType('Record<string, string>')]` on the class.

## Check the types in CI

Fail a pipeline when the committed files are outdated:

```bash
vendor/bin/typo3 typescript:generate --check
```

The command compares a fresh run with the manifest in the output folder, names each file that is out of date or no longer generated, and exits with an error. Pass the same `--output` you generate with.

## Common problems

### A prop is typed as `any`

A `ui:prop` declared `type="array"` generates `any`, because a PHP array is a list or an object once it is encoded. Declare a list as `type="string[]"` for a precise type. The command prints one warning with the number of props that qualify, and `-v` lists them.

### A class has no type

The command generates the class of a client prop, and every class or backed enum tagged with `#[TypeScript]` in the `Classes/` folder of your own extensions: your sitepackage and every extension installed from a path repository. Tag any other class, and keep one class per file, named after the class.

### `mountAll` gives untyped props

Check that `types.generated/*.d.ts` is part of your `include`, that you ran the command after the last change and that the component has client props.
