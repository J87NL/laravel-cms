<?php

declare(strict_types=1);

use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Mcp\ImageUploader;
use J87NL\Cms\Mcp\Tools\MediaUploadTool;
use J87NL\Cms\Media\Remote\HostResolver;
use J87NL\Cms\Media\Remote\RemoteImageDownloader;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Tests\Fixtures\Mcp\CmsTestServer;

/**
 * @param  array<string, list<string>>  $addresses
 */
function fakeDns(array $addresses): void
{
    app()->instance(HostResolver::class, new class($addresses) implements HostResolver
    {
        /**
         * @param  array<string, list<string>>  $addresses
         */
        public function __construct(private readonly array $addresses) {}

        public function resolve(string $host): array
        {
            return $this->addresses[$host] ?? [];
        }
    });
}

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

it('names the hard limit in the schema when the upload limit is off', function (): void {
    config()->set('cms.media.max_upload_kilobytes', 0);

    expect(app(MediaUploadTool::class)->toArray()['inputSchema']['properties']['content_base64']['description'])
        ->toContain(sprintf('at most %d kB', ImageUploader::HARD_MAX_BYTES / 1024));

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

it('accepts a matching sha256 and refuses a damaged transfer with the number of bytes that arrived', function (): void {
    $png = UploadedFile::fake()->image('logo.png', 30, 20)->getContent();
    $damaged = substr($png, 0, 40).'XY'.substr($png, 40);
    $before = temporaryUploads();

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'file_name' => 'logo.png',
            'content_base64' => base64_encode($damaged),
            'sha256' => hash('sha256', $png),
            'alt' => 'Logo',
        ])
        ->assertHasErrors([sprintf('The upload arrived damaged: the sha256 checksum does not match the %d bytes that were received', strlen($damaged))]);

    expect(MediaAsset::query()->count())->toBe(0)
        ->and(temporaryUploads())->toBe($before);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'file_name' => 'logo.png',
            'content_base64' => base64_encode($png),
            'sha256' => strtoupper(hash('sha256', $png)),
            'alt' => 'Logo',
        ])
        ->assertOk();

    expect(MediaAsset::query()->count())->toBe(1);
});

it('checks the checksum before it reads the file type', function (): void {
    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'file_name' => 'logo.png',
            'content_base64' => base64_encode('not an image'),
            'sha256' => hash('sha256', 'something else'),
            'alt' => 'Logo',
        ])
        ->assertHasErrors(['arrived damaged']);
});

it('refuses a sha256 that is not 64 hexadecimal characters', function (): void {
    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'file_name' => 'logo.png',
            'content_base64' => pngBase64(),
            'sha256' => 'abc',
            'alt' => 'Logo',
        ])
        ->assertHasErrors(['sha256 must be the SHA-256 of the file as 64 hexadecimal characters.']);
});

it('downloads an image from a public https address and names it after the address', function (): void {
    fakeDns(['images.example.com' => ['93.184.216.34']]);
    $png = UploadedFile::fake()->image('logo.png', 30, 20)->getContent();
    Http::fake(['https://images.example.com/logos/github-logo.png' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'source_url' => 'https://images.example.com/logos/github-logo.png',
            'sha256' => hash('sha256', $png),
            'alt' => 'Het GitHub-logo',
        ])
        ->assertOk();

    $asset = MediaAsset::query()->sole();

    expect($asset->alt)->toBe('Het GitHub-logo')
        ->and($asset->width())->toBe(30)
        ->and($asset->file()?->file_name)->toBe('github-logo.png');
});

it('follows a redirect to another public address', function (): void {
    fakeDns(['example.com' => ['93.184.216.34'], 'cdn.example.net' => ['2606:2800:220:1:248:1893:25c8:1946']]);
    Http::fake([
        'https://example.com/logo' => Http::response('', 302, ['Location' => 'https://cdn.example.net/logo.png']),
        'https://cdn.example.net/logo.png' => Http::response(UploadedFile::fake()->image('logo.png', 10, 10)->getContent()),
    ]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'source_url' => 'https://example.com/logo',
            'alt' => 'Logo',
        ])
        ->assertOk();

    expect(MediaAsset::query()->sole()->file()?->file_name)->toBe('logo.png');
});

it('refuses a source_url that is not public https before requesting it', function (string $url, array $dns, string $message): void {
    fakeDns($dns);
    Http::fake();

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'source_url' => $url,
            'alt' => 'Logo',
        ])
        ->assertHasErrors([$message]);

    Http::assertNothingSent();
    expect(MediaAsset::query()->count())->toBe(0);
})->with([
    'http' => ['http://example.com/logo.png', ['example.com' => ['93.184.216.34']], 'source_url must be a public https:// address.'],
    'loopback' => ['https://localhost/logo.png', ['localhost' => ['127.0.0.1']], 'not a public address'],
    'private network' => ['https://intranet.example.com/logo.png', ['intranet.example.com' => ['10.1.2.3']], 'not a public address'],
    'link-local metadata address' => ['https://169.254.169.254/latest/meta-data', [], 'not a public address'],
    'carrier-grade nat' => ['https://cgnat.example.com/logo.png', ['cgnat.example.com' => ['100.64.0.1']], 'not a public address'],
    'ipv6 loopback' => ['https://[::1]/logo.png', [], 'not a public address'],
    'ipv6 unique local' => ['https://ula.example.com/logo.png', ['ula.example.com' => ['fd12:3456::1']], 'not a public address'],
    'ipv4-mapped ipv6' => ['https://mapped.example.com/logo.png', ['mapped.example.com' => ['::ffff:127.0.0.1']], 'not a public address'],
    'one private address among public ones' => ['https://mixed.example.com/logo.png', ['mixed.example.com' => ['93.184.216.34', '192.168.1.10']], 'not a public address'],
    'unresolvable host' => ['https://nowhere.example.com/logo.png', [], 'does not resolve'],
    'other port' => ['https://example.com:8443/logo.png', ['example.com' => ['93.184.216.34']], 'standard https port 443'],
    'user info' => ['https://user:secret@example.com/logo.png', ['example.com' => ['93.184.216.34']], 'user name or password'],
]);

it('refuses a redirect to a private address', function (): void {
    fakeDns(['example.com' => ['93.184.216.34'], 'internal.example.com' => ['192.168.1.10']]);
    Http::fake([
        'https://example.com/logo.png' => Http::response('', 301, ['Location' => 'https://internal.example.com/secret.png']),
        '*' => Http::response('should not be requested', 200),
    ]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'source_url' => 'https://example.com/logo.png',
            'alt' => 'Logo',
        ])
        ->assertHasErrors(['not a public address']);

    Http::assertSentCount(1);
    expect(MediaAsset::query()->count())->toBe(0);
});

it('stops after three redirects', function (): void {
    fakeDns(['example.com' => ['93.184.216.34']]);
    Http::fake(fn (HttpRequest $request) => Http::response('', 302, ['Location' => $request->url().'x']));

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'source_url' => 'https://example.com/a',
            'alt' => 'Logo',
        ])
        ->assertHasErrors(['redirects more than 3 times']);

    Http::assertSentCount(4);
});

it('stops a download that passes the upload limit', function (array $headers): void {
    fakeDns(['example.com' => ['93.184.216.34']]);
    config()->set('cms.media.max_upload_kilobytes', 1);
    Http::fake(['https://example.com/big.png' => Http::response(str_repeat('a', 4096), 200, $headers)]);
    $before = temporaryUploads();

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'source_url' => 'https://example.com/big.png',
            'alt' => 'Groot',
        ])
        ->assertHasErrors(['larger than 1 kB, so the download was stopped']);

    expect(MediaAsset::query()->count())->toBe(0)
        ->and(temporaryUploads())->toBe($before);
})->with([
    'announced by Content-Length' => [['Content-Length' => '4096']],
    'without Content-Length' => [[]],
]);

it('refuses an error answer from the source', function (): void {
    fakeDns(['example.com' => ['93.184.216.34']]);
    Http::fake(['https://example.com/gone.png' => Http::response('', 404)]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'source_url' => 'https://example.com/gone.png',
            'alt' => 'Weg',
        ])
        ->assertHasErrors(['example.com answered HTTP 404 for source_url']);
});

it('takes either content_base64 or source_url, not both and not neither', function (array $arguments, string $message): void {
    Http::fake();

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, [
            'alt' => 'Logo',
            ...$arguments,
        ])
        ->assertHasErrors([$message]);

    Http::assertNothingSent();
})->with([
    'both' => [['file_name' => 'logo.png', 'content_base64' => 'aGFsbG8=', 'source_url' => 'https://example.com/logo.png'], 'content base64 field prohibits source url'],
    'neither' => [['file_name' => 'logo.png'], 'content base64 field is required when source url is not present'],
]);

it('pins the connection to the checked address, without a proxy or the stream handler', function (): void {
    fakeDns(['images.example.com' => ['93.184.216.34'], '2606:2800:220:1:248:1893:25c8:1946' => []]);
    $seen = [];
    $png = UploadedFile::fake()->image('logo.png', 10, 10)->getContent();

    Http::fake(function (HttpRequest $request, array $options) use (&$seen, $png) {
        $seen[] = $options;

        return Http::response($png);
    });

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, ['source_url' => 'https://images.example.com/logo.png', 'alt' => 'Logo'])
        ->assertOk();

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, ['source_url' => 'https://[2606:2800:220:1:248:1893:25c8:1946]/logo.png', 'alt' => 'Logo'])
        ->assertOk();

    expect($seen)->toHaveCount(2)
        ->and($seen[0]['curl'][CURLOPT_RESOLVE])->toBe(['images.example.com:443:93.184.216.34'])
        ->and($seen[0]['progress'])->toBeCallable()
        ->and($seen[0]['proxy'])->toBe('')
        ->and($seen[0]['allow_redirects'])->toBeFalse()
        ->and($seen[0]['stream'] ?? false)->toBeFalse()
        ->and($seen[1]['curl'])->not->toHaveKey(CURLOPT_RESOLVE);

    foreach ($seen as $options) {
        expect($options['sink'])->toBeResource();
        unset($options['sink']);

        $easy = (new CurlFactory(1))->create(new GuzzleRequest('GET', 'https://images.example.com/logo.png'), $options);
        curl_close($easy->handle);
    }
});

it('resolves a relative redirect against the current address', function (): void {
    fakeDns(['example.com' => ['93.184.216.34']]);
    Http::fake([
        'https://example.com/old/logo' => Http::response('', 302, ['Location' => '../new/logo.png']),
        'https://example.com/new/logo.png' => Http::response(UploadedFile::fake()->image('logo.png', 10, 10)->getContent()),
    ]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, ['source_url' => 'https://example.com/old/logo', 'alt' => 'Logo'])
        ->assertOk();

    expect(MediaAsset::query()->sole()->file()?->file_name)->toBe('logo.png');
});

it('turns transport failures into a refusal sentence', function (Closure $answer, string $message): void {
    fakeDns(['example.com' => ['93.184.216.34']]);
    Http::fake(['https://example.com/*' => $answer]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, ['source_url' => 'https://example.com/logo.png', 'alt' => 'Logo'])
        ->assertHasErrors([$message]);

    expect(MediaAsset::query()->count())->toBe(0);
})->with([
    'connection failure' => [fn () => fn () => throw new ConnectionException('cURL error 28'), 'example.com could not be reached or did not answer within 10 seconds'],
    'any other failure' => [fn () => fn () => throw new RuntimeException('TLS went wrong'), 'The download from source_url failed, so nothing was uploaded.'],
    'malformed Location' => [fn () => Http::response('', 302, ['Location' => 'https://exa mple.com:99999/x']), 'redirected to an address that is not valid'],
]);

it('only treats public addresses as public', function (string $address, bool $public): void {
    expect(RemoteImageDownloader::isPublic($address))->toBe($public);
})->with([
    'public ipv4' => ['93.184.216.34', true],
    'public ipv6' => ['2606:2800:220:1:248:1893:25c8:1946', true],
    'loopback' => ['127.0.0.1', false],
    'private' => ['172.16.5.4', false],
    'link-local' => ['169.254.169.254', false],
    'carrier-grade nat' => ['100.64.1.1', false],
    'benchmarking' => ['198.18.0.1', false],
    'multicast' => ['224.0.0.1', false],
    'ipv6 loopback' => ['::1', false],
    'ipv6 unspecified' => ['::', false],
    'ipv4-mapped' => ['::ffff:127.0.0.1', false],
    'ipv4-compatible' => ['::127.0.0.1', false],
    'ipv4-translated' => ['::ffff:0:7f00:1', false],
    'nat64' => ['64:ff9b::7f00:1', false],
    '6to4' => ['2002:7f00:1::1', false],
    'teredo' => ['2001:0:4136:e378::1', false],
    'unique local' => ['fd00::1', false],
    'link-local ipv6' => ['fe80::1', false],
    'documentation ipv6' => ['2001:db8::1', false],
    'new documentation ipv6' => ['3fff::1', false],
    'srv6' => ['5f00::1', false],
    'ipv6 multicast' => ['ff02::1', false],
    'not an address' => ['example.com', false],
]);

it('stops a body that grows past the limit after decoding, and a large redirect body is not kept', function (): void {
    fakeDns(['example.com' => ['93.184.216.34']]);
    config()->set('cms.media.max_upload_kilobytes', 1);
    Http::fake([
        'https://example.com/redirect' => Http::response(str_repeat('r', 900), 302, ['Location' => 'https://example.com/logo.png']),
        'https://example.com/logo.png' => Http::response(UploadedFile::fake()->image('logo.png', 5, 5)->getContent()),
        'https://example.com/bomb.png' => Http::response(str_repeat('a', 5000), 200, ['Content-Length' => '40', 'Content-Encoding' => 'gzip']),
    ]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, ['source_url' => 'https://example.com/bomb.png', 'alt' => 'Bom'])
        ->assertHasErrors(['larger than 1 kB, so the download was stopped']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, ['source_url' => 'https://example.com/redirect', 'alt' => 'Logo'])
        ->assertOk();

    expect(MediaAsset::query()->sole()->width())->toBe(5);
});

it('refuses past the limit from the progress callback, with the arguments Guzzle passes', function (): void {
    fakeDns(['example.com' => ['93.184.216.34']]);
    config()->set('cms.media.max_upload_kilobytes', 1);
    Http::fake(function (HttpRequest $request, array $options) {
        if ($options['progress'](0, 2048, 0, 0) === true) {
            throw new RuntimeException('The transfer was aborted by the progress callback');
        }

        return Http::response('never');
    });

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaUploadTool::class, ['source_url' => 'https://example.com/big.png', 'alt' => 'Groot'])
        ->assertHasErrors(['larger than 1 kB, so the download was stopped']);
});
