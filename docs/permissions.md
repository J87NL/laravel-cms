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
`cms.media` and `cms.redirects`, each for its own tools. `cms.pages.publish` and `cms.settings` are never checked in
this package; they exist for the admin. The public routes and the [actions](actions.md) check no permissions.

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

## The actions

The [actions](actions.md) check no permissions. The admin, or any other code that calls them, must authorize first,
for example `cms.pages.publish` before `ChangePageStatus`.
