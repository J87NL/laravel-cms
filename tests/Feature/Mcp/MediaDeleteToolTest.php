<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use J87NL\Cms\Actions\DeleteMedia;
use J87NL\Cms\Actions\SaveSiteSettings;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Mcp\Tools\BlockAddTool;
use J87NL\Cms\Mcp\Tools\MediaDeleteTool;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Tests\Fixtures\Mcp\CmsTestServer;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

it('deletes an unused image with its media rows and files', function (): void {
    $asset = uploadImage(alt: 'Oude foto');
    $path = $asset->file()?->getPathRelativeToRoot();
    $conversion = $asset->file()?->getPathRelativeToRoot('w480');

    Storage::disk('public')->assertExists([(string) $path, (string) $conversion]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaDeleteTool::class, ['media_ids' => [$asset->id]])
        ->assertOk()
        ->assertSee('1 of 1 images deleted.')
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->has('results', 1)
            ->where('results.0.id', $asset->id)
            ->where('results.0.status', 'deleted')
            ->where('results.0.alt', 'Oude foto')
            ->where('results.0.file_name', 'photo.jpg')
            ->etc());

    expect(MediaAsset::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0);

    Storage::disk('public')->assertMissing((string) $path);
    Storage::disk('public')->assertMissing((string) $conversion);
});

it('refuses an image in use and leaves it and its files alone', function (Closure $use): void {
    $asset = uploadImage(alt: 'In gebruik');
    $path = (string) $asset->file()?->getPathRelativeToRoot();
    $use($asset);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaDeleteTool::class, ['media_ids' => [$asset->id]])
        ->assertOk()
        ->assertSee('0 of 1 images deleted.')
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('results.0.status', 'in_use')
            ->where('results.0.alt', 'In gebruik')
            ->where('results.0.file_name', 'photo.jpg')
            ->where('results.0.usage_count', 1)
            ->etc());

    expect(MediaAsset::query()->whereKey($asset->id)->exists())->toBeTrue()
        ->and(Media::query()->count())->toBe(1);

    Storage::disk('public')->assertExists($path);
})->with([
    'in a live block' => [fn (MediaAsset $asset) => addBlock(makePage(['title' => 'Foto']), 'image', ['media_id' => $asset->id])],
    'as the share image of a page' => [fn (MediaAsset $asset) => makePage(['title' => 'Delen', 'og_image_id' => $asset->id])],
    'only in a pending revision' => [function (MediaAsset $asset): void {
        $page = makePage(['title' => 'Live']);

        CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
            ->tool(BlockAddTool::class, ['page_id' => $page->id, 'type' => 'image', 'data' => ['media_id' => $asset->id]])
            ->assertOk();

        expect(Block::query()->where('page_id', $page->id)->exists())->toBeFalse()
            ->and($page->pendingRevision()->exists())->toBeTrue();
    }],
    'in the site settings' => [fn (MediaAsset $asset) => app(SaveSiteSettings::class)->handle(['og_image_id' => $asset->id])],
]);

it('handles every id of a mixed batch on its own', function (): void {
    $unused = uploadImage(alt: 'Weg');
    $used = uploadImage(alt: 'Blijft');
    addBlock(makePage(['title' => 'Galerij']), 'gallery', ['media_ids' => [$used->id]]);
    $missing = $used->id + 100;

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaDeleteTool::class, ['media_ids' => [$used->id, $missing, $unused->id]])
        ->assertOk()
        ->assertSee('1 of 3 images deleted.')
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('results.0.id', $used->id)
            ->where('results.0.status', 'in_use')
            ->where('results.1', ['id' => $missing, 'status' => 'not_found'])
            ->where('results.2.id', $unused->id)
            ->where('results.2.status', 'deleted')
            ->etc());

    expect(MediaAsset::query()->pluck('id')->all())->toBe([$used->id]);

    Storage::disk('public')->assertExists((string) $used->file()?->getPathRelativeToRoot());
});

it('reports an unexpected failure for that id and still handles the others', function (): void {
    $first = uploadImage(alt: 'Eerste');
    $second = uploadImage(alt: 'Tweede');
    $third = uploadImage(alt: 'Derde');

    $real = app(DeleteMedia::class);
    app()->instance(DeleteMedia::class, new class($real, $second->id) extends DeleteMedia
    {
        public function __construct(private readonly DeleteMedia $real, private readonly int $failingId) {}

        public function handle(MediaAsset $asset): void
        {
            if ($asset->id === $this->failingId) {
                throw new RuntimeException('Lock wait timeout exceeded');
            }

            $this->real->handle($asset);
        }
    });

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaDeleteTool::class, ['media_ids' => [$first->id, $second->id, $third->id]])
        ->assertOk()
        ->assertSee('2 of 3 images deleted. The others were left alone; see the status per id.')
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('results.0.status', 'deleted')
            ->where('results.1.id', $second->id)
            ->where('results.1.status', 'failed')
            ->where('results.1.alt', 'Tweede')
            ->where('results.2.status', 'deleted')
            ->etc());

    expect(MediaAsset::query()->pluck('id')->all())->toBe([$second->id]);
});

it('rejects an empty list, more than 25 ids, duplicate ids and ids that are not integers', function (Closure $ids, string $message): void {
    $asset = uploadImage();

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaDeleteTool::class, ['media_ids' => $ids($asset)])
        ->assertHasErrors([$message]);

    expect(MediaAsset::query()->count())->toBe(1);
})->with([
    'empty' => [fn (MediaAsset $asset): array => [], 'The media ids field is required.'],
    'too many' => [fn (MediaAsset $asset): array => range(1, 26), 'The media ids field must not have more than 25 items.'],
    'duplicate' => [fn (MediaAsset $asset): array => [$asset->id, $asset->id], 'has a duplicate value'],
    'a boolean' => [fn (MediaAsset $asset): array => [true], 'must be an integer'],
    'a numeric string' => [fn (MediaAsset $asset): array => [(string) $asset->id], 'must be an integer'],
    'null' => [fn (MediaAsset $asset): array => [null], 'field is required'],
    'text' => [fn (MediaAsset $asset): array => ['abc'], 'must be an integer'],
]);
