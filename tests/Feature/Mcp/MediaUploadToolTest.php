<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Testing\Fluent\AssertableJson;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Mcp\Tools\MediaUploadTool;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Tests\Fixtures\Mcp\CmsTestServer;

/**
 * @return list<string>
 */
function temporaryUploads(): array
{
    return glob(sprintf('%s/cms-mcp-upload-*', sys_get_temp_dir())) ?: [];
}

it('uploads a png and returns its id for block data', function (): void {
    $before = temporaryUploads();

    $response = CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'file_name' => 'GitHub Logo.png',
            'content_base64' => pngBase64(40, 30),
            'alt' => 'Het GitHub-logo',
            'caption' => 'Logo',
            'focal_x' => 20,
            'focal_y' => 80,
        ]);

    $asset = MediaAsset::query()
        ->sole();

    $response->assertOk()
        ->assertSee(sprintf('Image %d uploaded', $asset->id))
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('media.id', $asset->id)
            ->where('media.alt', 'Het GitHub-logo')
            ->where('media.caption', 'Logo')
            ->where('media.width', 40)
            ->where('media.height', 30)
            ->where('media.in_use', false)
            ->where('media.url', fn (mixed $url): bool => is_string($url) && $url !== '')
            ->etc());

    expect($asset->alt)->toBe('Het GitHub-logo')
        ->and($asset->focal_x)->toBe(20)
        ->and($asset->focal_y)->toBe(80)
        ->and($asset->width())->toBe(40)
        ->and($asset->height())->toBe(30)
        ->and($asset->file()?->file_name)->toBe('github-logo.png')
        ->and($asset->file()?->mime_type)->toBe('image/png')
        ->and(temporaryUploads())->toBe($before);
});

it('accepts a data url prefix and names the file after its real type', function (): void {
    $jpeg = base64_encode(UploadedFile::fake()->image('photo.jpg', 20, 20)->getContent());

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'file_name' => 'foto.png',
            'content_base64' => sprintf('data:image/png;base64,%s', $jpeg),
            'alt' => 'Een foto',
        ])
        ->assertOk();

    $media = MediaAsset::query()
        ->sole()
        ->file();

    expect($media?->mime_type)->toBe('image/jpeg')
        ->and($media?->file_name)->toBe('foto.jpg');
});

it('refuses content that is not an accepted image, whatever the file name says', function (string $content, string $message): void {
    $before = temporaryUploads();

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'file_name' => 'logo.png',
            'content_base64' => base64_encode($content),
            'alt' => 'Logo',
        ])
        ->assertHasErrors([$message]);

    expect(MediaAsset::query()->count())->toBe(0)
        ->and(temporaryUploads())->toBe($before);
})->with([
    'svg' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10"/></svg>', 'The content is image/svg+xml, which the media library does not accept'],
    'html' => ['<html><body><script>alert(1)</script></body></html>', 'which the media library does not accept'],
    'text' => ['just some text', 'The content is text/plain'],
]);

it('refuses invalid base64', function (string $content): void {
    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'file_name' => 'logo.png',
            'content_base64' => $content,
            'alt' => 'Logo',
        ])
        ->assertHasErrors(['content_base64 is not valid base64']);

    expect(MediaAsset::query()->count())->toBe(0);
})->with([
    'invalid characters' => ['this is *not* base64!'],
    'empty after the prefix' => ['data:image/png;base64,'],
]);

it('refuses a file over the upload limit before writing it to disk', function (): void {
    config()->set('cms.media.max_upload_kilobytes', 1);
    $before = temporaryUploads();

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'file_name' => 'groot.png',
            'content_base64' => base64_encode(random_bytes(1025)),
            'alt' => 'Groot',
        ])
        ->assertHasErrors(['The image is larger than 1 kB']);

    expect(MediaAsset::query()->count())->toBe(0)
        ->and(temporaryUploads())->toBe($before);
});

it('refuses base64 that is too long before decoding it', function (string $content, string $message): void {
    config()->set('cms.media.max_upload_kilobytes', 1);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'file_name' => 'groot.png',
            'content_base64' => $content,
            'alt' => 'Groot',
        ])
        ->assertHasErrors([$message]);

    expect(MediaAsset::query()->count())->toBe(0);
})->with([
    'longer than the limit allows, with an invalid character' => [str_repeat('A', 2000).'*', 'The image is larger than 1 kB'],
    'longer than any file of the limit' => [str_repeat('A', 5000), 'content base64 field must not be greater than'],
]);

it('accepts a file of exactly the upload limit as base64 with line breaks', function (): void {
    $png = UploadedFile::fake()->image('photo.png', 10, 10)->getContent();
    config()->set('cms.media.max_upload_kilobytes', 1);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'file_name' => 'precies.png',
            'content_base64' => sprintf('data:image/png;base64,%s', chunk_split(base64_encode(str_pad($png, 1024, "\0")), 76, "\r\n")),
            'alt' => 'Precies',
        ])
        ->assertOk();

    expect(MediaAsset::query()->count())->toBe(1);
});

it('leaves the size out of the schema when the upload limit is off', function (): void {
    config()->set('cms.media.max_upload_kilobytes', 0);

    expect(app(MediaUploadTool::class)->toArray()['inputSchema']['properties']['content_base64']['description'])
        ->not->toContain('kB');

    config()->set('cms.media.max_upload_kilobytes', 2048);

    expect(app(MediaUploadTool::class)->toArray()['inputSchema']['properties']['content_base64']['description'])
        ->toContain('at most 2048 kB');
});

it('refuses missing or invalid attributes and removes the temporary file', function (array $arguments, string $message): void {
    $before = temporaryUploads();

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'file_name' => 'logo.png',
            'content_base64' => pngBase64(),
            ...$arguments,
        ])
        ->assertHasErrors([$message]);

    expect(MediaAsset::query()->count())->toBe(0)
        ->and(temporaryUploads())->toBe($before);
})->with([
    'missing alt' => [[], 'alt field is required'],
    'alt too long' => [['alt' => str_repeat('a', 251)], 'alt field must not be greater than 250 characters'],
    'focal point out of range' => [['alt' => 'Logo', 'focal_x' => 101], 'focal x field must not be greater than 100'],
]);
