# Blocks

A page's content is a list of blocks. A block is a `J87NL\Cms\Models\Block` in the `cms_blocks` table with a `type`
key, a JSON `data` column, a `sort_order` and an `is_visible` flag. The block type, registered in
`J87NL\Cms\Blocks\BlockRegistry`, decides which fields the data has, how it is cleaned on save and how it renders.

Editors add and edit blocks in the page editor of `j87nl/laravel-vue-cms`. The admin builds its block forms from the
registered types, see [Blocks](https://github.com/J87NL/laravel-vue-cms/blob/main/docs/screens/blocks.md) in its docs.

## Built-in block types

| Key | Class | Purpose |
|---|---|---|
| `rich_text` | `RichTextBlock` | Formatted text |
| `text_image` | `TextWithImageBlock` | Formatted text next to an image |
| `image` | `ImageBlock` | One image with its caption |
| `gallery` | `GalleryBlock` | A grid of images |
| `call_to_action` | `CallToActionBlock` | A title, a text and a button |
| `embed` | `EmbedBlock` | A YouTube (no-cookie) video or an OpenStreetMap map |
| `dates` | `DatesBlock` | A list of dates, emitted as schema.org `Event` |
| `contact_form` | `ContactFormBlock` | A contact form that mails the site owner |
| `link_tiles` | `LinkTilesBlock` | A grid of tiles linking to other sites, such as sponsors or social profiles |

All classes live in `J87NL\Cms\Blocks\Types`. The fields below are the keys of the block `data`. Required fields are
marked.

### rich_text

| Field | Rules |
|---|---|
| `html` (required) | string, at most 100,000 characters |

The HTML is sanitized when the block is saved and again when it is rendered. See
[Security](security.md#rich-text) for what survives.

### text_image

| Field | Rules |
|---|---|
| `html` (required) | string, at most 100,000 characters, sanitized like `rich_text` |
| `media_id` (required) | id of a media asset |
| `image_position` (required) | `left` or `right`, default `left` |

### image

| Field | Rules |
|---|---|
| `media_id` (required) | id of a media asset |

The image renders with its caption as `figcaption`.

### gallery

| Field | Rules |
|---|---|
| `media_ids` (required) | list of 1 to 60 distinct media asset ids |

### call_to_action

| Field | Rules |
|---|---|
| `title` (required) | string, at most 160 characters |
| `text` | string, at most 1,000 characters |
| `button_label` (required) | string, at most 80 characters |
| `url` (required) | a safe link, at most 2,048 characters |

A safe link is a path such as `/contact`, a `#fragment`, or a `http(s)://`, `mailto:` or `tel:` URL (the
`J87NL\Cms\Rules\SafeLink` rule). The link is checked again on render: a stored link that fails the check renders no
button.

### embed

| Field | Rules |
|---|---|
| `url` (required) | an allowed embed URL, at most 2,048 characters |
| `title` (required) | string, at most 160 characters, used as the iframe title for screen readers |

Only URLs of the providers in `cms.embeds` pass the `J87NL\Cms\Rules\AllowedEmbedUrl` rule. By default these are
`https://www.youtube-nocookie.com/embed/{11-character id}` and
`https://www.openstreetmap.org/export/embed.html?...`. The URL is checked again on render. See
[Security](security.md#embeds).

### dates

| Field | Rules |
|---|---|
| `title` | string, at most 160 characters |
| `location_name` | string, at most 160 characters |
| `location_address` | string, at most 255 characters |
| `items` (required) | list of 1 to 100 items |
| `items.*.date` (required) | a date |
| `items.*.title` (required) | string, at most 160 characters |
| `items.*.description` | string, at most 1,000 characters |

Items are sorted by date. When a location name or address is set, every item is added to the page's JSON-LD as a
schema.org `Event` with that location. Without a location, no events are emitted.

### link_tiles

| Field | Rules |
|---|---|
| `title` | string, at most 160 characters |
| `rel` | `noopener` (default), `me noopener` or `sponsored noopener` |
| `tiles` (required) | list of 1 to 100 tiles, reorderable |
| `tiles.*.name` (required) | string, at most 160 characters |
| `tiles.*.media_id` | id of a media asset |
| `tiles.*.url` | optional; an `https://` URL (lowercase scheme) that is also a safe link, at most 2,048 characters |

A tile shows its image, or its name when it has no image (or the image is missing or has no renditions). The image's alt
text is the tile name, so the tile keeps its name for screen readers with or without a link. A tile with a URL is a link
that opens in a new tab, with the block's `rel` value and the accessible name ":name (opens in a new tab)"; a tile
without a URL is not a link. The URL is optional whatever the `rel` value, so a social profiles block can have a tile
without a link too. The block is a `section` labelled by its heading, or with the visitor text
`cms::public.link_tiles.label` ("Links") when it has none.

Images render with `sizes="12rem"`. For smaller icons, publish and override the partial
`cms::blocks.partials.link-tile` with a matching `sizes` value. Choose `me noopener` for your own profiles (verification on Mastodon and similar sites) and
`sponsored noopener` for sponsors and other paid links. The URL is checked again on render, and a stored `rel` value
outside the three above renders as `noopener`. The block reports its images through `ReferencesMedia`, so an image used
on a tile cannot be deleted.

### contact_form

| Field | Rules |
|---|---|
| `title` | string, at most 160 characters |
| `intro` | string, at most 1,000 characters |
| `button_label` | string, at most 80 characters, default "Send" |
| `success_message` | string, at most 500 characters, a default thank-you text when empty |

See [Contact form](contact-form.md).

## Saving blocks

```php
use J87NL\Cms\Actions\SaveBlock;
use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Models\Block;

$type = app(BlockRegistry::class)->find('call_to_action');

$data = validator($input, $type->rules())->validate();

$block = app(SaveBlock::class)->handle($page, new Block, $type, $data, userId: auth()->id());
```

`SaveBlock::handle(Page $page, Block $block, BlockType $type, array $data, ?int $userId): Block` passes the data
through the type's `prepare()`, saves it and records a revision. A new block is added at the end of the page.
`SaveBlock` does not validate: validate the data with `$type->rules()` first.

Other block actions, each recording a revision:

| Action | Purpose |
|---|---|
| `DeleteBlock::handle(Block $block, ?int $userId)` | Delete a block |
| `ToggleBlockVisibility::handle(Block $block, ?int $userId)` | Show or hide a block. Hidden blocks are not rendered and do not count for the meta description |
| `ReorderBlocks::handle(Page $page, array $blockIds, ?int $userId)` | Set the order. The list must hold every block id of the page exactly once |
| `ReorderBlocks::move(Page $page, int $blockId, int $toBlockId, ?int $userId)` | Move one block to the position of another |

## The block registry

`BlockRegistry` is a singleton. Reach it through the container or the facade:

```php
use J87NL\Cms\Facades\Cms;

$registry = Cms::blocks();

$registry->all();        // array<string, BlockType>, without disabled types
$registry->has('image'); // bool
$registry->get('image'); // ?BlockType
$registry->find('image'); // BlockType, or an InvalidArgumentException
$registry->registered(); // array<string, BlockType>, disabled types included
```

To add your own types, see [Custom block types](custom-blocks.md).

## Disabling block types

List the keys of block types a site does not use in `cms.blocks.disabled`:

```php
'blocks' => [
    'disabled' => ['gallery', 'dates'],
],
```

A disabled type is left out of `all()`, `has()`, `get()` and `find()`. Existing blocks of that type are kept in the
database but not rendered. Media referenced by them still counts as in use.
