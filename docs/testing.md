# Testing

## The package's own tests

The package is tested with Pest on Orchestra Testbench:

```bash
composer update
vendor/bin/pest
```

The tests use an in-memory SQLite database and need the GD extension, because they upload real images. They run the
media library's `media` migration and the Testbench `users` migration next to the package's own migrations.

CI runs the suite once on `laravel/mcp` 0.9 and once on 1.x. To do the same locally:

```bash
composer update --with "laravel/mcp:^0.9.7"
vendor/bin/pest
composer update --with "laravel/mcp:^1.1.1"
vendor/bin/pest
```

## Testing your app

Create content with the [actions](actions.md), so paths, revisions and redirects are set up as in production:

```php
use J87NL\Cms\Actions\ChangePageStatus;
use J87NL\Cms\Actions\SavePage;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Models\Page;

test('the about page is public', function (): void {
    $home = app(SavePage::class)->handle(new Page, [
        'title' => 'Home',
    ], null);

    $about = app(SavePage::class)->handle(new Page, [
        'title' => 'About',
    ], null);

    app(ChangePageStatus::class)->handle($home, PageStatus::Published, null);
    app(ChangePageStatus::class)->handle($about, PageStatus::Published, null);

    $this->get('/about')->assertOk()->assertSee('About');
});
```

The first page that is published becomes the home page and its own path redirects to `/`, so publish a home page
first when you test another page at its own path.

The media library queues the WebP conversions. Set `media-library.queue_connection_name` to `sync` in a test that
needs them.

To test the [MCP tools](mcp-tools.md), call them on your server with `actingAs()` and set the grant header on the
request:

```php
use App\Mcp\Servers\SiteServer;
use J87NL\Cms\Mcp\CmsTools;
use J87NL\Cms\Mcp\Tools\PageDraftCreateTool;

request()->headers->set(CmsTools::GRANT_LEVEL_HEADER, CmsTools::FULL_GRANT);

SiteServer::actingAs($user)
    ->tool(PageDraftCreateTool::class, ['title' => 'New page'])
    ->assertOk();
```

Pass the tool class, not the tool name.

The user needs the tool's permission, see [Permissions](permissions.md).
