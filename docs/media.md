# Media

Images are `J87NL\Cms\Models\MediaAsset` records in the `cms_media_assets` table. The file itself is stored with
`spatie/laravel-medialibrary`, in the media collection `file` (`MediaAsset::COLLECTION`) on the disk from
`cms.media.disk`.

Editors upload and edit images in the admin of `j87nl/laravel-vue-cms`, see
[Media](https://github.com/J87NL/laravel-vue-cms/blob/main/docs/screens/media.md) in its docs.

## Fields

| Field | Purpose |
|---|---|
| `alt` | Alternative text, required |
| `caption` | Optional caption, rendered as `figcaption` |
| `focal_x` | Horizontal focal point, as a percentage from the left, default `50` |
| `focal_y` | Vertical focal point, as a percentage from the top, default `50` |

The focal point is used as `object-position` when an image is cropped to fill its box (the gallery and the text with
image block).

## Uploading

```php
use J87NL\Cms\Actions\UploadMedia;

$asset = app(UploadMedia::class)->handle($request->file('file'), [
    'alt' => $request->string('alt'),
    'caption' => $request->string('caption'),
]);
```

`UploadMedia::handle(UploadedFile $file, array $attributes): MediaAsset` takes the `alt`, `caption`, `focal_x` and
`focal_y` attributes. Before the file is stored, `J87NL\Cms\Media\ImageCleaner`:

1. refuses a file that is not a JPEG, PNG, WebP or GIF image, an image that cannot be read, or an image whose width
   times height is above `cms.media.max_pixels`;
2. refuses an animated GIF;
3. decodes the image with GD, applies the EXIF orientation of a JPEG (when the `exif` extension is available) and
   encodes it again (JPEG and WebP at quality 90).

Encoding again drops all metadata, such as EXIF and GPS locations. A refusal is a `ValidationException` on the `file`
key, and nothing is stored.

The media collection accepts `image/jpeg`, `image/png`, `image/webp` and `image/gif`. A file larger than
`cms.media.max_upload_kilobytes` (default 10,240) is refused with a `ValidationException` on `file`
(`cms::validation.media_too_large`) before anything is read. `UploadMedia` does not check the required alt text:
validate it in your request, for example with `J87NL\Cms\Validation\MediaRules::for('alt')`.

The stored file is named after the slug of the original file name. The width and height of the cleaned image are
stored as custom properties of the media item.

## Conversions

For every width in `cms.media.widths` (default 480, 960 and 1600) the media library creates a queued WebP conversion
named `w{width}`, for example `w960`. Images are never upscaled: a conversion keeps the original size when the
original is smaller.

Because the conversions are queued, run a queue worker. Until the conversions exist, the original file is used.

## Using an asset

| Method | Returns |
|---|---|
| `file()` | The media library `Media` item, or `null` |
| `width()`, `height()` | The size of the cleaned original |
| `originalUrl()` | The URL of the original file |
| `sources()` | A list of `['url' => ..., 'width' => ...]` for every generated conversion, or the original when there are none |
| `largestSource()` | The largest source with its height, or `null` |
| `thumbnailUrl()` | The URL of the smallest source |
| `objectPosition()` | The focal point as CSS, for example `50% 30%` |

On public pages, render an asset with the [`<x-cms::image>`](blade-components.md#image) component, which writes the
`srcset`, `sizes`, `width` and `height`.

## Deleting

```php
use J87NL\Cms\Actions\DeleteMedia;

app(DeleteMedia::class)->handle($asset);
```

An asset that is still in use is refused with a `ValidationException` on `media`. `J87NL\Cms\Media\MediaUsage`
counts the uses:

- blocks whose type implements `ReferencesMedia` and lists the asset (disabled block types included);
- pages that use the asset as `og_image_id`;
- the site settings `og_image_id` and `entity_image_id`.

`MediaUsage::counts()` returns `[assetId => count]` for all assets, `MediaUsage::count(MediaAsset $asset)` the count
for one. Deleting an unused asset also deletes its files.

## Select options

`J87NL\Cms\Media\MediaOptions::all()` returns every asset, newest first, as `['value' => id, 'label' => 'alt (#id)']`.
The built-in image fields use it as options, and `MediaOptions::existsRule()` as the rule that the id exists.
