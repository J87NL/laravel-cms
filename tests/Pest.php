<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use J87NL\Cms\Actions\SaveBlock;
use J87NL\Cms\Actions\SavePage;
use J87NL\Cms\Actions\UploadMedia;
use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Models\Redirect;
use J87NL\Cms\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

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
