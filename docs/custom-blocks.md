# Custom block types

A block type is a class that implements `J87NL\Cms\Blocks\BlockType`. Register it in a service provider and it can
be added to pages, is validated with its own rules and renders with its own Blade view. The admin of
`j87nl/laravel-vue-cms` shows a registered type with an add button and a form built from its `fields()`.

## The contract

```php
interface BlockType
{
    public function key(): string;

    public function label(): string;

    /** @return list<BaseField> */
    public function fields(): array;

    /** @return array<string, mixed> */
    public function rules(): array;

    public function prepare(array $data): array;

    public function view(): string;

    public function viewData(array $data, BlockContext $context): array;

    public function toPlainText(array $data): string;
}
```

| Method | Purpose |
|---|---|
| `key()` | The unique key stored in `cms_blocks.type`, for example `quote` |
| `label()` | The name of the type for editors |
| `fields()` | The form fields, as `tranquil-tools/laravel-form-builder` fields (`TranquilTools\FormBuilder\Fields\...`) |
| `rules()` | Validation rules for the block data |
| `prepare()` | Cleans the validated data before it is stored |
| `view()` | The Blade view that renders the block |
| `viewData()` | The variables passed to that view |
| `toPlainText()` | The block's text, used for the meta description fallback and `llms.txt`. Return `''` for blocks without text |

## AbstractBlockType

Extend `J87NL\Cms\Blocks\AbstractBlockType` to get defaults for four of the methods:

| Method | Default |
|---|---|
| `label()` | The translation of `cms::blocks.{key}.label` in the current locale |

`label()` should return translated text. Override it with `__('your.key')`, not with the bare key: the MCP block type
summary and the admin still pass a returned key through `__()`, so an old type that returns a key keeps working, but
other code that shows the label gets the key.
| `rules()` | The rules of `fields()`, collected with `FormConfig::make($this->fields())->getRules()` |
| `prepare()` | The data unchanged |
| `viewData()` | `['data' => $data]` |

It also has two protected helpers: `string(array $data, string $key): string` returns a scalar value as a string
(or `''`), and `plain(string $html): string` turns HTML into one line of plain text.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Cms\Blocks;

use J87NL\Cms\Blocks\AbstractBlockType;
use J87NL\Cms\Blocks\BlockContext;
use TranquilTools\FormBuilder\Fields\Text;
use TranquilTools\FormBuilder\Fields\Textarea;

class QuoteBlock extends AbstractBlockType
{
    public function key(): string
    {
        return 'quote';
    }

    public function label(): string
    {
        return 'Quote';
    }

    public function view(): string
    {
        return 'blocks.quote';
    }

    public function fields(): array
    {
        return [
            Textarea::make('quote')
                ->label('Quote')
                ->required()
                ->rules([
                    'string',
                    'max:1000',
                ]),

            Text::make('author')
                ->label('Author')
                ->rules([
                    'nullable',
                    'string',
                    'max:160',
                ]),
        ];
    }

    public function prepare(array $data): array
    {
        return [
            'quote' => trim($this->string($data, 'quote')),
            'author' => trim($this->string($data, 'author')),
        ];
    }

    public function viewData(array $data, BlockContext $context): array
    {
        return [
            'quote' => $this->string($data, 'quote'),
            'author' => $this->string($data, 'author'),
        ];
    }

    public function toPlainText(array $data): string
    {
        return $this->string($data, 'quote');
    }
}
```

**View** (`resources/views/blocks/quote.blade.php`):

```blade
<blockquote class="cms-block cms-block--quote">
    <p>{{ $quote }}</p>

    @if ($author !== '')
        <footer>{{ $author }}</footer>
    @endif
</blockquote>
```

Escape every value with `{{ }}`. The view runs on public pages, and stored data may not be what your form sent.

## Registering

Register the type in the `boot()` method of a service provider:

```php
use App\Cms\Blocks\QuoteBlock;
use J87NL\Cms\Facades\Cms;

public function boot(): void
{
    Cms::blocks()->register(QuoteBlock::class);
}
```

`register()` takes a class name, which is resolved from the container (so constructor dependencies are injected), or
an instance. Registering a type with the key of an existing type replaces that type.

## The view

The view receives the array from `viewData()`, plus:

- `$block` - the `Block` model;
- `$context` - a `J87NL\Cms\Blocks\BlockContext`.

`BlockContext` has these members:

| Member | Purpose |
|---|---|
| `$context->page` | The `Page` being rendered |
| `$context->position` | The position of the block among the rendered blocks, starting at `0` |
| `$context->isFirst()` | `true` for the first rendered block. The built-in image blocks load eagerly there and lazily elsewhere |
| `$context->asset(mixed $id)` | A preloaded `MediaAsset`, or `null` |
| `$context->assets(array $ids)` | The preloaded assets for these ids, unknown ids skipped |
| `$context->block` | The `Block` model |

Only media that the block type reports through `ReferencesMedia` is preloaded, see below.

## Optional interfaces

### ReferencesMedia

```php
use J87NL\Cms\Blocks\ReferencesMedia;

class BannerBlock extends AbstractBlockType implements ReferencesMedia
{
    public function mediaIds(array $data): array
    {
        $id = (int) $this->string($data, 'media_id');

        return $id > 0 ? [$id] : [];
    }
}
```

`mediaIds()` returns the ids of the media assets a block uses. The renderer loads those assets in one query for the
whole page, so `$context->asset()` finds them. `J87NL\Cms\Media\MediaUsage` also counts them as usage, so `DeleteMedia`
refuses to delete an image in use. Validate media ids with `J87NL\Cms\Media\MediaOptions::existsRule()`;
`MediaOptions::all()` returns the assets as `value` and `label` pairs for a select field.

### HasJsonLd

```php
use J87NL\Cms\Blocks\HasJsonLd;

class ProductBlock extends AbstractBlockType implements HasJsonLd
{
    public function jsonLd(array $data): array
    {
        return [
            [
                '@type' => 'Product',
                'name' => $this->string($data, 'name'),
            ],
        ];
    }
}
```

`jsonLd()` returns a list of nodes. Each node is added to the page's JSON-LD `@graph`, see
[SEO tags and JSON-LD](seo.md). Only visible blocks contribute.

## Rich text fields

`J87NL\Cms\Blocks\RichTextField::make(string $name, string $label)` returns a `Wysiwyg` field set up with the
`cms.rich_text` editor and options and the rules `string` and `max:100000`. Sanitize its value in `prepare()` and
again in `viewData()` with `J87NL\Cms\Html\RichTextSanitizer::sanitize()`, as the built-in rich text blocks do, and
print it with `{!! !!}`.

## Translating the built-in labels

The built-in types return the translation of `cms::blocks.{key}.label` from `label()`, such as "Text" for
`rich_text`. The admin and the block type summary of the MCP tools show that label. The package ships
English and Dutch strings in `lang/en/blocks.php` and `lang/nl/blocks.php`. Override them in
`lang/vendor/cms/{locale}/blocks.php` of your app.
