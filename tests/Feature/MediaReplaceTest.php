<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Actions\ReplaceMediaFile;
use J87NL\Cms\Cache\PageCache;
use J87NL\Cms\Models\MediaAsset;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
});

function storedPaths(MediaAsset $asset): array
{
    $media = $asset->file();

    return [
        $media->getPathRelativeToRoot(),
        ...array_map(
            fn (int $width): string => $media->getPathRelativeToRoot(MediaAsset::conversionName($width)),
            MediaAsset::widths(),
        ),
    ];
}

function replaceWith(MediaAsset $asset, int $width = 1200, int $height = 900, string $name = 'replacement.png'): MediaAsset
{
    return app(ReplaceMediaFile::class)->handle($asset, UploadedFile::fake()->image($name, $width, $height));
}

test('replacing the file keeps the asset and its details but swaps the file and conversions', function (): void {
    $asset = uploadImage(2000, 1000, 'The beach');
    $asset->update([
        'caption' => 'Cap Blanc-Nez',
        'focal_x' => 30,
        'focal_y' => 70,
    ]);
    $oldMediaId = $asset->file()->id;
    $oldSources = collect($asset->sources())->pluck('url')->all();

    $replaced = replaceWith($asset, 700, 350);

    expect($replaced->id)->toBe($asset->id)
        ->and($replaced->only(['alt', 'caption', 'focal_x', 'focal_y']))->toBe([
            'alt' => 'The beach',
            'caption' => 'Cap Blanc-Nez',
            'focal_x' => 30,
            'focal_y' => 70,
        ])
        ->and($replaced->file()->id)->not->toBe($oldMediaId)
        ->and($replaced->width())->toBe(700)
        ->and($replaced->height())->toBe(350)
        ->and($replaced->file()->hasGeneratedConversion('w480'))->toBeTrue()
        ->and(collect($replaced->sources())->pluck('width')->all())->toBe([480, 700])
        ->and(array_intersect($oldSources, collect($replaced->sources())->pluck('url')->all()))->toBe([])
        ->and($replaced->getMedia(MediaAsset::COLLECTION))->toHaveCount(1)
        ->and(MediaAsset::query()->count())->toBe(1);
});

test('the old original and conversions are removed from the disk', function (): void {
    $asset = uploadImage();
    $oldPaths = storedPaths($asset);

    foreach ($oldPaths as $path) {
        Storage::disk('public')->assertExists($path);
    }

    $replaced = replaceWith($asset);

    foreach ($oldPaths as $path) {
        Storage::disk('public')->assertMissing($path);
    }

    Storage::disk('public')->assertExists($replaced->file()->getPathRelativeToRoot());
    expect($replaced->media()->count())->toBe(1);
});

test('blocks that use the asset render the new file without relinking', function (): void {
    $asset = uploadImage(2000, 1000, 'Harbour');
    $page = makePage([
        'slug' => 'harbour',
    ]);
    addBlock($page, 'image', [
        'media_id' => $asset->id,
    ]);
    $oldUrl = $asset->file()->getUrl('w480');

    $this->get('/harbour')->assertOk()->assertSee($oldUrl, false);

    $replaced = replaceWith($asset, 1200, 900);

    $this->get('/harbour')
        ->assertOk()
        ->assertSee($replaced->file()->getUrl('w480'), false)
        ->assertDontSee($oldUrl, false)
        ->assertSee('width="1200"', false)
        ->assertSee('alt="Harbour"', false);
});

test('replacing the file flushes the page cache', function (): void {
    $asset = uploadImage();
    $cache = app(PageCache::class);
    $version = $cache->version();

    replaceWith($asset);

    expect($cache->version())->toBeGreaterThan($version);
});

test('a refused file leaves the current file in place', function (UploadedFile $file): void {
    config()->set('cms.media.max_upload_kilobytes', 100);

    $asset = uploadImage();
    $mediaId = $asset->file()->id;
    $paths = storedPaths($asset);

    expect(fn () => app(ReplaceMediaFile::class)->handle($asset, $file))->toThrow(ValidationException::class);

    expect($asset->fresh()->file()->id)->toBe($mediaId)
        ->and($asset->fresh()->media()->count())->toBe(1);

    foreach ($paths as $path) {
        Storage::disk('public')->assertExists($path);
    }
})->with([
    'too large' => fn () => UploadedFile::fake()->image('big.jpg', 800, 600)->size(101),
    'not an image' => fn () => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
    'failed upload' => fn () => new UploadedFile(UploadedFile::fake()->image('broken.jpg')->getRealPath(), 'broken.jpg', 'image/jpeg', UPLOAD_ERR_PARTIAL, true),
]);

test('the old files stay until the surrounding transaction commits', function (): void {
    $asset = uploadImage();
    $paths = storedPaths($asset);

    DB::transaction(function () use ($asset, $paths): void {
        replaceWith($asset);

        foreach ($paths as $path) {
            Storage::disk('public')->assertExists($path);
        }
    });

    foreach ($paths as $path) {
        Storage::disk('public')->assertMissing($path);
    }
});

test('a rolled back replacement keeps the old file and its files', function (): void {
    $asset = uploadImage();
    $mediaId = $asset->file()->id;
    $paths = storedPaths($asset);
    $newPath = null;

    try {
        DB::transaction(function () use ($asset, &$newPath): void {
            $newPath = replaceWith($asset)->file()->getPathRelativeToRoot();

            Storage::disk('public')->assertExists($newPath);

            throw new RuntimeException('Rolled back');
        });
    } catch (RuntimeException) {
    }

    $fresh = MediaAsset::query()
        ->findOrFail($asset->id);

    expect($fresh->file()->id)->toBe($mediaId)
        ->and($fresh->media()->count())->toBe(1);

    foreach ($paths as $path) {
        Storage::disk('public')->assertExists($path);
    }

    Storage::disk('public')->assertMissing($newPath);
});

test('a failure inside the replacement removes the files of the new file', function (): void {
    $asset = uploadImage();
    $mediaId = $asset->file()->id;
    $paths = storedPaths($asset);
    $before = Storage::disk('public')->allFiles();

    MediaAsset::saving(fn () => throw new RuntimeException('Touch failed'));

    expect(fn () => replaceWith($asset))->toThrow(RuntimeException::class, 'Touch failed');

    expect($asset->fresh()->file()->id)->toBe($mediaId)
        ->and($asset->fresh()->media()->count())->toBe(1)
        ->and(Storage::disk('public')->allFiles())->toBe($before);

    foreach ($paths as $path) {
        Storage::disk('public')->assertExists($path);
    }
});

test('an old file whose files cannot be removed stays retired and is cleaned up by the next replacement', function (): void {
    $exceptions = Exceptions::fake();

    $asset = uploadImage();
    $oldMediaId = $asset->file()->id;
    $oldPath = $asset->file()->getPathRelativeToRoot();
    $failing = true;
    $filesystem = app(Filesystem::class);

    app()->instance(Filesystem::class, Mockery::mock($filesystem)
        ->makePartial()
        ->shouldReceive('removeAllFiles')
        ->andReturnUsing(function (Media $media) use (&$failing, $filesystem): void {
            if ($failing) {
                throw new RuntimeException('Disk unavailable');
            }

            $filesystem->removeAllFiles($media);
        })
        ->getMock());

    $replaced = replaceWith($asset);

    expect($replaced->file()->id)->not->toBe($oldMediaId)
        ->and(Media::query()->findOrFail($oldMediaId)->collection_name)->toBe(ReplaceMediaFile::RETIRED_COLLECTION);

    Storage::disk('public')->assertExists($oldPath);
    $exceptions->assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Disk unavailable');

    $failing = false;

    $again = replaceWith($asset);

    expect(Media::query()->whereKey($oldMediaId)->exists())->toBeFalse()
        ->and($again->media()->count())->toBe(1);

    Storage::disk('public')->assertMissing($oldPath);
});
