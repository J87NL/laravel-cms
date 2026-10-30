<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Mcp\ImageUploader;
use J87NL\Cms\Mcp\Tools\MediaUploadChunkTool;
use J87NL\Cms\Mcp\Tools\MediaUploadFinishTool;
use J87NL\Cms\Mcp\Tools\MediaUploadStartTool;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Models\MediaUploadSession;
use J87NL\Cms\Tests\Fixtures\Mcp\CmsTestServer;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * A PNG with enough noise to need several chunks.
 */
function noisyPng(int $width = 60, int $height = 40): string
{
    $image = imagecreatetruecolor($width, $height);
    mt_srand(87);

    for ($x = 0; $x < $width; $x++) {
        for ($y = 0; $y < $height; $y++) {
            imagesetpixel($image, $x, $y, (int) imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
    }

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * @return array<string, mixed>
 */
function structuredResult(mixed $response): array
{
    $result = [];

    $response->assertStructuredContent(function (AssertableJson $json) use (&$result): AssertableJson {
        $result = $json->toArray();

        return $json->etc();
    });

    return $result;
}

/**
 * @param  array<string, mixed>  $extra
 */
function startUpload(User $user, string $file, array $extra = []): string
{
    $response = CmsTestServer::actingAs($user)
        ->tool(MediaUploadStartTool::class, [
            'file_name' => 'logo.png',
            'size' => strlen($file),
            'sha256' => hash('sha256', $file),
            'alt' => 'Logo',
            ...$extra,
        ])
        ->assertOk();

    return (string) structuredResult($response)['upload_id'];
}

/**
 * @return list<string>
 */
function chunksOf(string $file): array
{
    return str_split($file, MediaUploadSession::CHUNK_BYTES);
}

function sendChunk(User $user, string $uploadId, int $index, string $bytes, ?string $sha256 = null): mixed
{
    return CmsTestServer::actingAs($user)
        ->tool(MediaUploadChunkTool::class, [
            'upload_id' => $uploadId,
            'index' => $index,
            'content_base64' => base64_encode($bytes),
            'sha256' => $sha256 ?? hash('sha256', $bytes),
        ]);
}

it('uploads an image in chunks and returns it like media_upload', function (): void {
    $file = noisyPng();
    $user = cmsUser(CmsPermission::Media);

    $start = CmsTestServer::actingAs($user)
        ->tool(MediaUploadStartTool::class, [
            'file_name' => 'Palette Logo.png',
            'size' => strlen($file),
            'sha256' => hash('sha256', $file),
            'alt' => 'Een logo',
            'caption' => 'Logo',
            'focal_x' => 30,
        ]);

    $chunks = chunksOf($file);

    $start->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('chunk_bytes', MediaUploadSession::CHUNK_BYTES)
            ->where('chunk_count', count($chunks))
            ->etc());

    $uploadId = (string) structuredResult($start)['upload_id'];

    foreach (array_reverse($chunks, true) as $index => $chunk) {
        sendChunk($user, $uploadId, $index, $chunk)->assertOk();
    }

    $finish = CmsTestServer::actingAs($user)
        ->tool(MediaUploadFinishTool::class, ['upload_id' => $uploadId]);

    $asset = MediaAsset::query()->sole();

    $finish->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('media.id', $asset->id)
            ->where('media.alt', 'Een logo')
            ->where('media.width', 60)
            ->where('media.height', 40)
            ->etc());

    expect(count($chunks))->toBeGreaterThan(1)
        ->and($asset->caption)->toBe('Logo')
        ->and($asset->focal_x)->toBe(30)
        ->and($asset->file()?->file_name)->toBe('palette-logo.png')
        ->and(MediaUploadSession::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses a damaged chunk on its own and takes it when it is sent again', function (): void {
    $file = noisyPng();
    $user = cmsUser(CmsPermission::Media);
    $uploadId = startUpload($user, $file);
    $chunks = chunksOf($file);

    sendChunk($user, $uploadId, 0, $chunks[0])->assertOk();

    $damaged = substr($chunks[1], 0, 100).substr($chunks[1], 103);

    sendChunk($user, $uploadId, 1, $damaged, hash('sha256', $chunks[1]))
        ->assertHasErrors([sprintf('Chunk 1 arrived damaged: the sha256 checksum does not match the %d bytes that were received, so it was not stored. Send chunk 1 again.', strlen($damaged))]);

    $session = MediaUploadSession::query()->sole();

    expect(Storage::disk('local')->exists($session->chunkPath(0)))->toBeTrue()
        ->and(Storage::disk('local')->exists($session->chunkPath(1)))->toBeFalse();

    foreach (array_slice($chunks, 1, null, true) as $index => $chunk) {
        sendChunk($user, $uploadId, $index, $chunk)->assertOk();
    }

    CmsTestServer::actingAs($user)
        ->tool(MediaUploadFinishTool::class, ['upload_id' => $uploadId])
        ->assertOk();

    expect(MediaAsset::query()->count())->toBe(1);
});

it('replaces a chunk that is sent again', function (): void {
    $file = noisyPng();
    $user = cmsUser(CmsPermission::Media);
    $uploadId = startUpload($user, $file);
    $chunks = chunksOf($file);
    $wrong = str_repeat('x', MediaUploadSession::CHUNK_BYTES);

    sendChunk($user, $uploadId, 0, $wrong)->assertOk();

    foreach ($chunks as $index => $chunk) {
        sendChunk($user, $uploadId, $index, $chunk)->assertOk();
    }

    CmsTestServer::actingAs($user)
        ->tool(MediaUploadFinishTool::class, ['upload_id' => $uploadId])
        ->assertOk();
});

it('refuses a chunk of the wrong size or with an index out of range', function (): void {
    $file = noisyPng();
    $user = cmsUser(CmsPermission::Media);
    $uploadId = startUpload($user, $file);
    $count = count(chunksOf($file));

    sendChunk($user, $uploadId, 0, 'too short')
        ->assertHasErrors([sprintf('Chunk 0 holds 9 bytes, but it must hold %d bytes of the file', MediaUploadSession::CHUNK_BYTES)]);

    sendChunk($user, $uploadId, $count, 'x')
        ->assertHasErrors([sprintf('has chunks 0 to %d, so there is no chunk %d', $count - 1, $count)]);
});

it('lists the missing chunks when finishing too early and keeps the upload', function (): void {
    $file = noisyPng();
    $user = cmsUser(CmsPermission::Media);
    $uploadId = startUpload($user, $file);
    $chunks = chunksOf($file);

    sendChunk($user, $uploadId, 1, $chunks[1])->assertOk();

    $missing = array_values(array_diff(array_keys($chunks), [1]));

    CmsTestServer::actingAs($user)
        ->tool(MediaUploadFinishTool::class, ['upload_id' => $uploadId])
        ->assertHasErrors([sprintf('is missing %d of its %d chunks: %s.', count($missing), count($chunks), implode(', ', $missing))]);

    expect(MediaUploadSession::query()->sole()->finishing_at)->toBeNull()
        ->and(MediaAsset::query()->count())->toBe(0);
});

it('refuses a joined file whose sha256 does not match and keeps the upload', function (): void {
    $file = noisyPng();
    $user = cmsUser(CmsPermission::Media);
    $uploadId = startUpload($user, $file, ['sha256' => hash('sha256', 'another file')]);

    foreach (chunksOf($file) as $index => $chunk) {
        sendChunk($user, $uploadId, $index, $chunk)->assertOk();
    }

    CmsTestServer::actingAs($user)
        ->tool(MediaUploadFinishTool::class, ['upload_id' => $uploadId])
        ->assertHasErrors(['does not match the size and sha256 given to media_upload_start']);

    $session = MediaUploadSession::query()->sole();

    expect($session->finishing_at)->toBeNull()
        ->and(Storage::disk('local')->exists($session->chunkPath(0)))->toBeTrue()
        ->and(MediaAsset::query()->count())->toBe(0);
});

it('refuses content that is not an accepted image when finishing and removes the upload', function (): void {
    $svg = str_pad('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>', 3000, ' ');
    $user = cmsUser(CmsPermission::Media);
    $uploadId = startUpload($user, $svg);

    foreach (chunksOf($svg) as $index => $chunk) {
        sendChunk($user, $uploadId, $index, $chunk)->assertOk();
    }

    CmsTestServer::actingAs($user)
        ->tool(MediaUploadFinishTool::class, ['upload_id' => $uploadId])
        ->assertHasErrors(['which the media library does not accept']);

    expect(MediaUploadSession::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(MediaAsset::query()->count())->toBe(0);
});

it('does not let another user see or use an upload', function (): void {
    $file = noisyPng();
    $owner = cmsUser(CmsPermission::Media);
    $uploadId = startUpload($owner, $file);
    $other = cmsUser(CmsPermission::Media);

    sendChunk($other, $uploadId, 0, chunksOf($file)[0])
        ->assertHasErrors([sprintf('Upload %s was not found.', $uploadId)]);

    CmsTestServer::actingAs($other)
        ->tool(MediaUploadFinishTool::class, ['upload_id' => $uploadId])
        ->assertHasErrors([sprintf('Upload %s was not found.', $uploadId)]);

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('does not find an expired upload or an unknown id', function (): void {
    $file = noisyPng();
    $user = cmsUser(CmsPermission::Media);
    $uploadId = startUpload($user, $file);

    $this->travel(61)->minutes();

    sendChunk($user, $uploadId, 0, chunksOf($file)[0])
        ->assertHasErrors(['was not found. It may have expired']);

    sendChunk($user, 'not-a-uuid', 0, 'x')
        ->assertHasErrors(['Upload not-a-uuid was not found.']);
});

it('prunes expired uploads with their chunks and keeps open ones', function (): void {
    $file = noisyPng();
    $user = cmsUser(CmsPermission::Media);
    $expiredId = startUpload($user, $file);
    sendChunk($user, $expiredId, 0, chunksOf($file)[0])->assertOk();

    $this->travel(61)->minutes();

    $openId = startUpload($user, $file);
    sendChunk($user, $openId, 0, chunksOf($file)[0])->assertOk();

    $this->artisan('cms:prune-media-uploads')
        ->expectsOutput('Pruned 1 expired media uploads.')
        ->assertSuccessful();

    expect(MediaUploadSession::query()->pluck('id')->all())->toBe([$openId])
        ->and(Storage::disk('local')->allFiles())->toBe([MediaUploadSession::query()->sole()->chunkPath(0)]);
});

it('schedules the pruning every hour, also when the cms leaves page scheduling to the app', function (bool $schedulingEnabled): void {
    config()->set('cms.scheduling.enabled', $schedulingEnabled);

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'cms:prune-media-uploads'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
})->with([
    'page scheduling on' => [true],
    'page scheduling off' => [false],
]);

it('refuses a start over the upload limit, with invalid attributes or past the open upload cap', function (): void {
    $user = cmsUser(CmsPermission::Media);
    config()->set('cms.media.max_upload_kilobytes', 1);

    CmsTestServer::actingAs($user)
        ->tool(MediaUploadStartTool::class, ['file_name' => 'a.png', 'size' => 1025, 'sha256' => str_repeat('a', 64), 'alt' => 'A'])
        ->assertHasErrors(['The image is larger than 1 kB']);

    CmsTestServer::actingAs($user)
        ->tool(MediaUploadStartTool::class, ['file_name' => 'a.png', 'size' => 10, 'sha256' => str_repeat('a', 64), 'alt' => str_repeat('a', 251)])
        ->assertHasErrors(['alt field must not be greater than 250 characters']);

    config()->set('cms.media.chunked_uploads.max_open_per_user', 2);

    foreach ([1, 2] as $attempt) {
        CmsTestServer::actingAs($user)
            ->tool(MediaUploadStartTool::class, ['file_name' => 'a.png', 'size' => 10, 'sha256' => str_repeat('a', 64), 'alt' => 'A'])
            ->assertOk();
    }

    CmsTestServer::actingAs($user)
        ->tool(MediaUploadStartTool::class, ['file_name' => 'a.png', 'size' => 10, 'sha256' => str_repeat('a', 64), 'alt' => 'A'])
        ->assertHasErrors(['You already have 2 unfinished chunked uploads']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadStartTool::class, ['file_name' => 'a.png', 'size' => 10, 'sha256' => str_repeat('a', 64), 'alt' => 'A'])
        ->assertOk();

    expect(MediaUploadSession::query()->count())->toBe(3);
});

it('refuses the chunk and finish tools without media permission or a full grant', function (string $tool, ?string $grant, string $message): void {
    $owner = cmsUser(CmsPermission::Media);
    $uploadId = startUpload($owner, 'abc');

    hubGrant($grant);

    CmsTestServer::actingAs($grant === 'full' ? cmsUser(CmsPermission::PagesEdit) : $owner)
        ->tool($tool, ['upload_id' => $uploadId, 'index' => 0, 'content_base64' => base64_encode('abc'), 'sha256' => hash('sha256', 'abc')])
        ->assertHasErrors([$message]);

    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'chunk without permission' => [MediaUploadChunkTool::class, 'full', 'lacks the CMS permission "cms.media"'],
    'chunk under a read grant' => [MediaUploadChunkTool::class, 'read', 'grant for this app is read-only'],
    'finish without permission' => [MediaUploadFinishTool::class, 'full', 'lacks the CMS permission "cms.media"'],
    'finish under a read grant' => [MediaUploadFinishTool::class, 'read', 'grant for this app is read-only'],
]);

it('accepts a one-byte last chunk', function (): void {
    $png = UploadedFile::fake()->image('logo.png', 10, 10)->getContent();
    $file = str_pad($png, MediaUploadSession::CHUNK_BYTES + 1, "\0");
    $user = cmsUser(CmsPermission::Media);
    $uploadId = startUpload($user, $file);
    $chunks = chunksOf($file);

    expect(strlen($chunks[1]))->toBe(1);

    foreach ($chunks as $index => $chunk) {
        sendChunk($user, $uploadId, $index, $chunk)->assertOk();
    }

    CmsTestServer::actingAs($user)
        ->tool(MediaUploadFinishTool::class, ['upload_id' => $uploadId])
        ->assertOk();
});

it('refuses a second finish and further chunks while an upload is being finished', function (): void {
    $file = noisyPng();
    $user = cmsUser(CmsPermission::Media);
    $uploadId = startUpload($user, $file);

    foreach (chunksOf($file) as $index => $chunk) {
        sendChunk($user, $uploadId, $index, $chunk)->assertOk();
    }

    MediaUploadSession::query()->whereKey($uploadId)->update(['finishing_at' => now()]);

    CmsTestServer::actingAs($user)
        ->tool(MediaUploadFinishTool::class, ['upload_id' => $uploadId])
        ->assertHasErrors([sprintf('Upload %s is already being finished.', $uploadId)]);

    sendChunk($user, $uploadId, 0, chunksOf($file)[0])
        ->assertHasErrors([sprintf('Upload %s is being finished, so it takes no more chunks.', $uploadId)]);

    expect(MediaAsset::query()->count())->toBe(0);
});

it('refuses a joined file of the wrong size and keeps the upload', function (): void {
    $file = noisyPng();
    $user = cmsUser(CmsPermission::Media);
    $uploadId = startUpload($user, $file);

    foreach (chunksOf($file) as $index => $chunk) {
        sendChunk($user, $uploadId, $index, $chunk)->assertOk();
    }

    $session = MediaUploadSession::query()->sole();
    Storage::disk('local')->put($session->chunkPath(0), 'short');

    CmsTestServer::actingAs($user)
        ->tool(MediaUploadFinishTool::class, ['upload_id' => $uploadId])
        ->assertHasErrors(['does not match the size and sha256 given to media_upload_start']);

    expect($session->refresh()->finishing_at)->toBeNull();
});

it('keeps an upload open for an hour after its last chunk', function (): void {
    $file = noisyPng();
    $user = cmsUser(CmsPermission::Media);
    $uploadId = startUpload($user, $file);
    $chunks = chunksOf($file);

    $this->travel(50)->minutes();
    sendChunk($user, $uploadId, 0, $chunks[0])->assertOk();

    $this->travel(50)->minutes();
    sendChunk($user, $uploadId, 1, $chunks[1])->assertOk();

    $this->travel(61)->minutes();
    sendChunk($user, $uploadId, 2, $chunks[2])->assertHasErrors(['was not found']);
});

it('prunes chunk directories that have no upload', function (): void {
    Storage::disk('local')->put('cms-media-uploads/0199c2a0-0000-7000-8000-000000000000/00000.part', 'left behind');

    $this->artisan('cms:prune-media-uploads')->assertSuccessful();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('keeps a hard limit on chunked uploads when the upload limit is off', function (): void {
    config()->set('cms.media.max_upload_kilobytes', 0);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadStartTool::class, ['file_name' => 'a.png', 'size' => ImageUploader::HARD_MAX_BYTES + 1, 'sha256' => str_repeat('a', 64), 'alt' => 'A'])
        ->assertHasErrors([sprintf('The image is larger than %d kB', ImageUploader::HARD_MAX_BYTES / 1024)]);
});
