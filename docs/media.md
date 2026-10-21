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
(`cms::validation.media_too_large`) before anything is read; `0` switches that check off. A failed upload (an
`UploadedFile` that is not valid) is refused the same way. `UploadMedia` also validates the attributes with
`J87NL\Cms\Validation\MediaRules`: `alt` is required, and `caption`, `focal_x` and `focal_y` are checked when given.

The stored file is named after the slug of the original file name. The width and height of the cleaned image are
stored as custom properties of the media item.

## Conversions

For every width in `cms.media.widths` (default 480, 960 and 1600) the media library creates a queued WebP conversion
named `w{width}`, for example `w960`. Images are never upscaled: a conversion keeps the original size when the
original is smaller.

Because the conversions are queued, run a queue worker. Until the conversions exist, the original file is used.

## Replacing the file

```php
use J87NL\Cms\Actions\ReplaceMediaFile;

$asset = app(ReplaceMediaFile::class)->handle($asset, $request->file('file'));
```

`ReplaceMediaFile::handle(MediaAsset $asset, UploadedFile $file): MediaAsset` gives an asset a new file while it keeps
its id, alt text, caption and focal point. Blocks, pages and settings refer to the asset by id, so they show the new
file without being relinked.

The new file goes through the same checks as an upload: the size limit `cms.media.max_upload_kilobytes`, a failed
upload, and `ImageCleaner` with the same refusals and metadata stripping. A refusal is a `ValidationException` on
`file`, and the current file stays in place.

In one transaction, the action locks the asset, moves its current media item out of the `file` collection (into
`replaced`), stores the new file in it and touches the asset. The media library then creates new conversions for every
width in `cms.media.widths`, under new URLs, so browsers and proxies never keep showing the old image. The old original
and its conversions are deleted only after the transaction commits; when it rolls back, the old file stays and the files
of the new one are removed. After the commit, the files of each old media item are removed first and its row is deleted
after that. When removing the files fails, the replacement still succeeds: the error is reported, the old media item
stays in the `replaced` collection, and the next replacement of the asset tries again. Saving the new media item flushes
the [page cache](page-cache.md).

There is no MCP tool to upload or replace a file: files are only added and replaced in the admin.

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
for one. Deleting an unused asset also deletes its files. `DeleteMedia` locks the asset row while it counts, deletes the
rows in a transaction and removes the files only after it commits, so a rolled back delete keeps the files and a
replacement that runs at the same time cannot leave files behind.

## Select options

`J87NL\Cms\Media\MediaOptions::all()` returns every asset, newest first, as `['value' => id, 'label' => 'alt (#id)']`.
The built-in image fields use it as options, and `MediaOptions::existsRule()` as the rule that the id exists.
