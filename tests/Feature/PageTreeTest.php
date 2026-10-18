<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Actions\MovePage;
use J87NL\Cms\Actions\SavePage;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Pages\PageTree;
use J87NL\Cms\Pages\PageVisibility;

test('a slug change recomputes the path of the whole subtree', function (): void {
    $root = makePage([
        'title' => 'Root',
        'slug' => 'root',
    ]);
    $child = makePage([
        'title' => 'Child',
        'slug' => 'child',
        'parent_id' => $root->id,
    ]);
    $grandchild = makePage([
        'title' => 'Grandchild',
        'slug' => 'grandchild',
        'parent_id' => $child->id,
    ]);

    app(SavePage::class)->handle($root, [
        'title' => 'Root',
        'slug' => 'renamed',
    ], null);

    expect($child->fresh()->path)->toBe('renamed/child')
        ->and($grandchild->fresh()->path)->toBe('renamed/child/grandchild');
});

test('a parent change recomputes the path of the whole subtree', function (): void {
    $first = makePage([
        'slug' => 'first',
    ]);
    $second = makePage([
        'slug' => 'second',
    ]);
    $child = makePage([
        'slug' => 'child',
        'parent_id' => $first->id,
    ]);
    $grandchild = makePage([
        'slug' => 'grandchild',
        'parent_id' => $child->id,
    ]);

    app(MovePage::class)->handle($child, $second, MovePage::INSIDE, null);

    expect($child->fresh()->path)->toBe('second/child')
        ->and($grandchild->fresh()->path)->toBe('second/child/grandchild');
});

test('a page cannot be moved below its own subtree', function (): void {
    $parent = makePage([
        'slug' => 'parent',
    ]);
    $child = makePage([
        'slug' => 'child',
        'parent_id' => $parent->id,
    ]);

    expect(fn () => app(MovePage::class)->handle($parent, $child, MovePage::INSIDE, null))
        ->toThrow(ValidationException::class);

    expect($parent->fresh()->parent_id)->toBeNull();
});

test('two pages cannot share a path', function (): void {
    makePage([
        'slug' => 'about',
    ]);

    expect(fn () => makePage([
        'slug' => 'about',
    ]))->toThrow(ValidationException::class);
});

test('a page cannot take a path the application uses', function (): void {
    Route::get('admin', fn (): string => 'admin');

    expect(fn () => makePage([
        'slug' => 'admin',
    ]))->toThrow(ValidationException::class);
});

test('moving a page before or after a sibling reorders the siblings', function (): void {
    $first = makePage([
        'slug' => 'first',
    ]);
    $second = makePage([
        'slug' => 'second',
    ]);
    $third = makePage([
        'slug' => 'third',
    ]);

    app(MovePage::class)->handle($third, $first, MovePage::BEFORE, null);

    expect(Page::query()
        ->whereNull('parent_id')->orderBy('sort_order')->pluck('slug')->all())
        ->toBe(['third', 'first', 'second']);

    app(MovePage::class)->handle($third, $second, MovePage::AFTER, null);

    expect(Page::query()
        ->whereNull('parent_id')->orderBy('sort_order')->pluck('slug')->all())
        ->toBe(['first', 'second', 'third']);
});

test('a path longer than the column allows is rejected for the page and its subtree', function (): void {
    $parent = makePage([
        'slug' => 'parent',
    ]);
    makePage([
        'slug' => str_repeat('b', 200),
        'parent_id' => $parent->id,
    ]);

    expect(fn () => makePage([
        'slug' => str_repeat('a', 256),
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(SavePage::class)->handle($parent, [
        'title' => 'Parent',
        'slug' => str_repeat('p', 60),
    ], null))->toThrow(ValidationException::class);

    expect($parent->fresh()->path)->toBe('parent');
});

test('a rename that would move a subpage onto an application route is rejected', function (): void {
    Route::get('invitations/{token}', fn (): string => 'invitation');

    $parent = makePage([
        'slug' => 'x',
    ]);
    $child = makePage([
        'slug' => 'foo',
        'parent_id' => $parent->id,
    ]);

    expect(fn () => app(SavePage::class)->handle($parent, [
        'title' => 'Invitations',
        'slug' => 'invitations',
    ], null))->toThrow(ValidationException::class);

    expect($parent->fresh()->path)->toBe('x')
        ->and($child->fresh()->path)->toBe('x/foo');
});

test('a unique path race ends in a validation error instead of a server error', function (): void {
    makePage([
        'slug' => 'about',
    ]);

    app()->instance(PageTree::class, new class(app('router'), app(PageVisibility::class)) extends PageTree
    {
        public function assertValidPlacement(Page $page): void {}
    });

    expect(fn () => app(SavePage::class)->handle(new Page, [
        'title' => 'About',
        'slug' => 'about',
    ], null))->toThrow(ValidationException::class);
});
