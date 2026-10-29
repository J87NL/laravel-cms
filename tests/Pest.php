<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use J87NL\Cms\Actions\SaveBlock;
use J87NL\Cms\Actions\SavePage;
use J87NL\Cms\Actions\UploadMedia;
use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Mcp\CmsTools;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Models\Redirect;
use J87NL\Cms\Models\Revision;
use J87NL\Cms\Pages\PendingRevisionEditor;
use J87NL\Cms\Tests\TestCase;
use Orchestra\Testbench\Factories\UserFactory;

pest()->extend(TestCase::class)->in('Feature');

pest()->beforeEach(function (): void {
    hubGrant(CmsTools::FULL_GRANT);
    Storage::fake('public');
})->in('Feature/Mcp');

/**
 * @param  array<string, mixed>  $attributes
 */
function makePage(array $attributes = [], PageStatus $status = PageStatus::Published, bool $home = false): Page
{
    $page = app(SavePage::class)->handle(new Page, [
        'title' => 'Page',
        ...$attributes,
    ], null);

    $page->status = $status;
    $page->is_home = $home;
    $page->save();

    return $page->refresh();
}

/**
 * @param  array<string, mixed>  $data
 */
function addBlock(Page $page, string $type, array $data): Block
{
    return app(SaveBlock::class)->handle($page, new Block, app(BlockRegistry::class)->find($type), $data, null);
}

function uploadImage(int $width = 2000, int $height = 1000, string $alt = 'A photo'): MediaAsset
{
    return app(UploadMedia::class)->handle(UploadedFile::fake()->image('photo.jpg', $width, $height), [
        'alt' => $alt,
    ]);
}

function pngBase64(int $width = 40, int $height = 30): string
{
    return base64_encode(UploadedFile::fake()->image('photo.png', $width, $height)->getContent());
}

/**
 * @return array<string, string>
 */
function redirectTargets(): array
{
    return Redirect::query()
        ->orderBy('from_path')
        ->pluck('to', 'from_path')
        ->all();
}

function cmsUser(CmsPermission ...$permissions): User
{
    $user = UserFactory::new()->create();
    $granted = array_map(fn (CmsPermission $permission): string => $permission->value, $permissions);

    Gate::before(fn (mixed $actor, string $ability): ?bool => $actor instanceof User && $actor->is($user) && in_array($ability, $granted, true) ? true : null);

    return $user;
}

function hubGrant(?string $level): void
{
    if (is_null($level)) {
        request()->headers->remove(CmsTools::GRANT_LEVEL_HEADER);

        return;
    }

    request()->headers->set(CmsTools::GRANT_LEVEL_HEADER, $level);
}

function makeRedirect(string $from, string $to, int $statusCode = 301): Redirect
{
    return Redirect::query()
        ->create([
            'from_path' => $from,
            'to' => $to,
            'status_code' => $statusCode,
        ]);
}

/**
 * @return list<int>
 */
function pageBlockIds(Page $page): array
{
    return Block::query()
        ->where('page_id', $page->id)
        ->orderBy('sort_order')
        ->orderBy('id')
        ->pluck('id')
        ->all();
}

function pendingPage(string $title = 'Live'): Page
{
    $page = makePage(['title' => $title]);

    app(PendingRevisionEditor::class)->updatePage($page, ['title' => sprintf('%s concept', $title)], null);

    return $page;
}

/**
 * @return list<array{int, mixed}>
 */
function pendingBlockSummary(Revision $revision): array
{
    $blocks = $revision->snapshot['blocks'];

    usort($blocks, fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);

    return array_map(fn (array $block): array => [
        $block['id'],
        $block['data']['html'] ?? null,
    ], $blocks);
}
