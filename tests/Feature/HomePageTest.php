<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use J87NL\Cms\Actions\ChangePageStatus;
use J87NL\Cms\Actions\MakeHomePage;
use J87NL\Cms\Actions\MovePage;
use J87NL\Cms\Actions\SavePage;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Models\Page;

function homePageIds(): array
{
    return Page::query()
        ->where('is_home', true)
        ->pluck('id')
        ->all();
}

test('a draft never becomes the home page and publishing the first page makes it home', function (): void {
    $draft = app(SavePage::class)->handle(new Page, [
        'title' => 'Draft',
    ], null);

    expect(homePageIds())->toBe([]);

    $second = app(SavePage::class)->handle(new Page, [
        'title' => 'Second',
    ], null);

    app(ChangePageStatus::class)->handle($second, PageStatus::Published, null);
    app(ChangePageStatus::class)->handle($draft, PageStatus::Published, null);

    expect(homePageIds())->toBe([$second->id]);
});

test('only a public page can become the home page', function (): void {
    $home = makePage([
        'slug' => 'home',
    ], home: true);
    $draft = makePage([
        'slug' => 'draft',
    ], PageStatus::Draft);
    $hiddenParent = makePage([
        'slug' => 'hidden',
    ], PageStatus::Unpublished);
    $childOfHidden = makePage([
        'slug' => 'child',
        'parent_id' => $hiddenParent->id,
    ]);

    expect(fn () => app(MakeHomePage::class)->handle($draft))->toThrow(ValidationException::class)
        ->and(fn () => app(MakeHomePage::class)->handle($childOfHidden))->toThrow(ValidationException::class);

    $published = makePage([
        'slug' => 'published',
    ]);

    app(MakeHomePage::class)->handle($published);

    expect(homePageIds())->toBe([$published->id])
        ->and($home->fresh()->is_home)->toBeFalse();
});

test('the home page and its ancestors cannot be taken offline', function (): void {
    $parent = makePage([
        'slug' => 'parent',
    ]);
    $home = makePage([
        'slug' => 'home',
        'parent_id' => $parent->id,
    ], home: true);

    expect(fn () => app(ChangePageStatus::class)->handle($home, PageStatus::Unpublished, null))->toThrow(ValidationException::class)
        ->and(fn () => app(ChangePageStatus::class)->handle($parent, PageStatus::Unpublished, null))->toThrow(ValidationException::class);

    expect($home->fresh()->status)->toBe(PageStatus::Published)
        ->and($parent->fresh()->status)->toBe(PageStatus::Published);
});

test('the home page and its ancestors cannot move below a page that is not public', function (): void {
    $parent = makePage([
        'slug' => 'parent',
    ]);
    $home = makePage([
        'slug' => 'home',
        'parent_id' => $parent->id,
    ], home: true);
    $hidden = makePage([
        'slug' => 'hidden',
    ], PageStatus::Draft);
    $public = makePage([
        'slug' => 'public',
    ]);

    expect(fn () => app(MovePage::class)->handle($home, $hidden, MovePage::INSIDE, null))->toThrow(ValidationException::class)
        ->and(fn () => app(MovePage::class)->handle($parent, $hidden, MovePage::INSIDE, null))->toThrow(ValidationException::class)
        ->and(fn () => app(SavePage::class)->handle($parent, [
            'title' => 'Parent',
            'slug' => 'parent',
            'parent_id' => $hidden->id,
        ], null))->toThrow(ValidationException::class);

    app(MovePage::class)->handle($parent, $public, MovePage::INSIDE, null);

    expect($home->fresh()->path)->toBe('public/parent/home');
});
