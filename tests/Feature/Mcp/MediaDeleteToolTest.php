<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Mcp\Tools\BlockAddTool;
use J87NL\Cms\Mcp\Tools\MediaDeleteTool;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Tests\Fixtures\Mcp\CmsTestServer;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

it('deletes an unused image with its media rows and files', function (): void {
    $asset = uploadImage(alt: 'Oude foto');
    $path = $asset->file()?->getPathRelativeToRoot();
    $conversion = $asset->file()?->getPathRelativeToRoot('w480');

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
        CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
            ->tool(BlockAddTool::class, ['page_id' => makePage(['title' => 'Live'])->id, 'type' => 'image', 'data' => ['media_id' => $asset->id]])
            ->assertOk();
    }],
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
});

it('rejects an empty list, more than 25 ids and duplicate ids', function (array $ids, string $message): void {
    $asset = uploadImage();

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaDeleteTool::class, ['media_ids' => $ids === ['duplicate'] ? [$asset->id, $asset->id] : $ids])
        ->assertHasErrors([$message]);

    expect(MediaAsset::query()->count())->toBe(1);
})->with([
    'empty' => [[], 'The media ids field is required.'],
    'too many' => [range(1, 26), 'The media ids field must not have more than 25 items.'],
    'duplicate' => [['duplicate'], 'has a duplicate value'],
]);
