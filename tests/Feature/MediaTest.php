<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Actions\DeleteMedia;
use J87NL\Cms\Actions\SaveSiteSettings;
use J87NL\Cms\Actions\UpdateMedia;
use J87NL\Cms\Actions\UploadMedia;
use J87NL\Cms\Models\MediaAsset;

beforeEach(function (): void {
    Storage::fake('public');
});

test('an upload stores the original size and creates webp conversions', function (): void {
    $asset = uploadImage(2000, 1000);

    $media = $asset->file();

    expect($asset->width())->toBe(2000)
        ->and($asset->height())->toBe(1000)
        ->and($media->hasGeneratedConversion('w480'))->toBeTrue()
        ->and($media->hasGeneratedConversion('w1600'))->toBeTrue()
        ->and($media->getPath('w960'))->toEndWith('.webp')
        ->and(collect($asset->sources())->pluck('width')->all())->toBe([480, 960, 1600]);
});

test('small images are not upscaled', function (): void {
    $asset = uploadImage(700, 350);

    expect(collect($asset->sources())->pluck('width')->all())->toBe([480, 700]);
});

test('images render responsive and lazy except in the first block', function (): void {
    $first = uploadImage(alt: 'First photo');
    $second = uploadImage(alt: 'Second photo');

    $page = makePage([
        'slug' => 'photos',
    ]);
    addBlock($page, 'image', [
        'media_id' => $first->id,
    ]);
    addBlock($page, 'image', [
        'media_id' => $second->id,
    ]);

    $html = (string) $this->get('/photos')->assertOk()->getContent();

    preg_match_all('/<img[^>]*>/s', $html, $matches);

    [$firstImage, $secondImage] = $matches[0];

    expect($firstImage)
        ->toContain('alt="First photo"')
        ->toContain('loading="eager"')
        ->toContain('480w')
        ->toContain('1600w')
        ->toContain('sizes="')
        ->toContain('width="2000"')
        ->toContain('height="1000"')
        ->and($secondImage)
        ->toContain('alt="Second photo"')
        ->toContain('loading="lazy"');
});

test('media in use cannot be deleted', function (): void {
    $asset = uploadImage();
    $page = makePage();
    addBlock($page, 'gallery', [
        'media_ids' => [$asset->id],
    ]);

    expect(fn () => app(DeleteMedia::class)->handle($asset))->toThrow(ValidationException::class);

    expect(MediaAsset::query()
        ->whereKey($asset->id)->exists())->toBeTrue();
});

test('media used in the site settings cannot be deleted', function (): void {
    $asset = uploadImage();

    app(SaveSiteSettings::class)->handle([
        'og_image_id' => $asset->id,
    ]);

    expect(fn () => app(DeleteMedia::class)->handle($asset))->toThrow(ValidationException::class);
});

test('shared pages use the largest webp version as open graph image', function (): void {
    $asset = uploadImage(2000, 1000);

    makePage([
        'title' => 'Photo',
        'slug' => 'photo',
        'og_image_id' => $asset->id,
    ]);

    $this->get('/photo')
        ->assertSee('<meta property="og:image:width" content="1600">', false)
        ->assertSee('<meta property="og:image:height" content="800">', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false);
});

test('unused media can be deleted together with its files', function (): void {
    $asset = uploadImage();
    $path = $asset->file()->getPathRelativeToRoot();

    app(DeleteMedia::class)->handle($asset);

    expect(MediaAsset::query()
        ->whereKey($asset->id)->exists())->toBeFalse();

    Storage::disk('public')->assertMissing($path);
});

test('location metadata is removed from the stored original', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'gps').'.jpg';
    $image = imagecreatetruecolor(640, 480);
    imagejpeg($image, $source);

    $tiff = "II\x2A\x00\x08\x00\x00\x00"
        ."\x01\x00"."\x25\x88\x04\x00\x01\x00\x00\x00\x1A\x00\x00\x00"."\x00\x00\x00\x00"
        ."\x01\x00"."\x01\x00\x02\x00\x02\x00\x00\x00N\x00\x00\x00"."\x00\x00\x00\x00";
    $exif = "Exif\x00\x00".$tiff;
    $segment = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
    $jpeg = (string) file_get_contents($source);
    file_put_contents($source, substr($jpeg, 0, 2).$segment.substr($jpeg, 2));

    expect(exif_read_data($source))->toHaveKey('GPSLatitudeRef');

    $asset = app(UploadMedia::class)->handle(new UploadedFile($source, 'photo.jpg', 'image/jpeg', null, true), [
        'alt' => 'Photo',
    ]);

    $stored = Storage::disk('public')->path($asset->file()->getPathRelativeToRoot());

    expect((string) file_get_contents($stored))->not->toContain('Exif')
        ->and($asset->width())->toBe(640);
});

test('an upload above the configured size is refused and stores nothing', function (): void {
    config()->set('cms.media.max_upload_kilobytes', 100);

    expect(fn () => app(UploadMedia::class)->handle(UploadedFile::fake()->image('big.jpg', 800, 600)->size(101), [
        'alt' => 'Too big',
    ]))->toThrow(ValidationException::class, __('cms::validation.media_too_large', ['max' => 100]));

    expect(MediaAsset::query()->count())->toBe(0);

    $asset = app(UploadMedia::class)->handle(UploadedFile::fake()->image('fits.jpg', 800, 600)->size(100), [
        'alt' => 'Fits',
    ]);

    expect($asset->exists)->toBeTrue();
});

test('updating media changes only its details', function (): void {
    $asset = uploadImage();
    $mediaId = $asset->file()->id;

    app(UpdateMedia::class)->handle($asset, [
        'alt' => 'New alt',
        'caption' => 'A caption',
        'focal_x' => 20,
        'focal_y' => 80,
        'id' => 999,
    ]);

    $asset->refresh();

    expect($asset->alt)->toBe('New alt')
        ->and($asset->caption)->toBe('A caption')
        ->and($asset->focal_x)->toBe(20)
        ->and($asset->focal_y)->toBe(80)
        ->and($asset->id)->not->toBe(999)
        ->and($asset->file()->id)->toBe($mediaId);
});

test('updating media refuses a focal point outside the image', function (string $field, int $value): void {
    $asset = uploadImage();

    expect(fn () => app(UpdateMedia::class)->handle($asset, [
        $field => $value,
    ]))->toThrow(ValidationException::class);

    expect($asset->refresh()->{$field})->toBe(50);
})->with([
    'x below zero' => ['focal_x', -1],
    'y above hundred' => ['focal_y', 101],
]);

test('a max upload size of zero switches the size check off', function (): void {
    config()->set('cms.media.max_upload_kilobytes', 0);

    $asset = app(UploadMedia::class)->handle(UploadedFile::fake()->image('big.jpg', 800, 600)->size(50_000), [
        'alt' => 'Large',
    ]);

    expect($asset->exists)->toBeTrue();
});

test('a failed upload is refused before its size or content is read', function (): void {
    $file = new UploadedFile(UploadedFile::fake()->image('broken.jpg')->getRealPath(), 'broken.jpg', 'image/jpeg', UPLOAD_ERR_PARTIAL, true);

    expect(fn () => app(UploadMedia::class)->handle($file, [
        'alt' => 'Broken',
    ]))->toThrow(ValidationException::class);

    expect(MediaAsset::query()->count())->toBe(0);
});

test('an upload validates its details', function (array $attributes): void {
    expect(fn () => app(UploadMedia::class)->handle(UploadedFile::fake()->image('photo.jpg', 800, 600), $attributes))
        ->toThrow(ValidationException::class);

    expect(MediaAsset::query()->count())->toBe(0);
})->with([
    'no alt' => [['caption' => 'Only a caption']],
    'focal point outside' => [['alt' => 'Photo', 'focal_x' => 150]],
    'caption too long' => [['alt' => 'Photo', 'caption' => str_repeat('a', 501)]],
]);
