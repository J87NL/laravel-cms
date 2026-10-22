# Permissions

The package names six permissions in the `J87NL\Cms\CmsPermission` enum:

| Case | Name | Label |
|---|---|---|
| `CmsPermission::PagesEdit` | `cms.pages.edit` | Edit pages |
| `CmsPermission::PagesPublish` | `cms.pages.publish` | Publish pages |
| `CmsPermission::Menus` | `cms.menus` | Manage menus |
| `CmsPermission::Media` | `cms.media` | Manage media |
| `CmsPermission::Redirects` | `cms.redirects` | Manage redirects |
| `CmsPermission::Settings` | `cms.settings` | Manage site settings |

`CmsPermission::names()` returns the names, and `$permission->label()` the translated label
(`cms::permissions.{name}`).

## Where the package checks them

The package itself only checks permissions in the [MCP tools](mcp-tools.md): `cms.pages.edit`, `cms.menus`,
`cms.media` and `cms.redirects`, each for its own tools, and `cms.pages.publish` (on top of `cms.pages.edit`) for the
live tools, `page_publish`, `page_unpublish` and `revision_discard`. `cms.settings` is never checked in this package; it
exists for the admin. The public routes and the [actions](actions.md) check no permissions.

The admin of `j87nl/laravel-vue-cms` checks all six on its routes. Which permission opens which screen is listed in
its [permissions documentation](https://github.com/J87NL/laravel-vue-cms/blob/main/docs/permissions.md).

## The permissions config key

When the service provider registers, it pushes every permission name into the config key named by
`cms.permissions_config_key` (default `j87nl.permissions`), skipping names that are already there. Other packages and
the app can read that list to show the permissions in a role editor:

```php
config('j87nl.permissions'); // ['cms.pages.edit', 'cms.pages.publish', ...]
```

## Granting them

The package does not define gates and does not know your roles. The [MCP tools](mcp-tools.md) check a permission with
`Gate::forUser($user)->allows('cms.pages.edit')`, so the app must define a gate (or a `Gate::before` callback) for
each name. A minimal setup in a service provider:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use J87NL\Cms\CmsPermission;

public function boot(): void
{
    foreach (CmsPermission::names() as $permission) {
        Gate::define($permission, fn (User $user): bool => $user->is_admin);
    }
}
```

Replace `$user->is_admin` with your own role check.

## The cms.pages.write gate

The MCP page and block tools also check a gate named `cms.pages.write` with the page as argument, but only when the
app defines it (`Gate::has('cms.pages.write')`). Use it to restrict which pages a user may change:

```php
use J87NL\Cms\Models\Page;

Gate::define('cms.pages.write', fn (User $user, Page $page): bool => $user->is_admin || ! $page->is_home);
```

The rule in this example (only admins may change the home page) stands for your own rules.

### With laravel-vue-cms installed

`j87nl/laravel-vue-cms` defines this gate itself: a user with `cms.pages.edit` may change a page that is not published,
and a published page only with `cms.pages.publish` as well. See
[Changing a published page](https://github.com/J87NL/laravel-vue-cms/blob/main/docs/permissions.md#changing-a-published-page).

For the MCP tools the admin's rule only matters for direct writes: a live tool on a published page needs
`cms.pages.publish`, which the live tools check anyway. A draft tool on a published page writes to the page's pending
revision and does not check the gate, so an editor with only `cms.pages.edit` can propose changes that someone with
`cms.pages.publish` publishes (see [MCP tools](mcp-tools.md#draft-and-live-mode)). `page_publish`, `page_unpublish`
and `revision_discard` check the gate, so your own rules (such as the home page example above) also decide who may put
a proposal live.

Your own `Gate::define('cms.pages.write', ...)` replaces the admin's gate, because app service providers boot after
package providers. Admin users would then lose the published-page rule without any warning. To add your own rule,
combine it with the admin's rule from `J87NL\VueCms\Support\PageWriteAccess`:

```php
use J87NL\Cms\Models\Page;
use J87NL\VueCms\Support\PageWriteAccess;

Gate::define(PageWriteAccess::ABILITY, function (User $user, Page $page): bool {
    if (! PageWriteAccess::allows($user, $page)) {
        return false;
    }

    return $user->is_admin || ! $page->is_home;
});
```

## The actions

The [actions](actions.md) check no permissions. The admin, or any other code that calls them, must authorize first,
for example `cms.pages.publish` before `ChangePageStatus`. The admin of `j87nl/laravel-vue-cms` does this on every
route.
