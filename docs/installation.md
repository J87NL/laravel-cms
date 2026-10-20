# Installation

## Requirements

- PHP 8.5 with the GD extension (uploaded images are decoded and re-encoded with GD)
- Laravel 13
- `spatie/laravel-medialibrary` 11 with its `media` table (installed as a dependency)
- A queue worker for the WebP conversions and the contact form mail
- The Laravel scheduler for planned publishing
- A cache store that supports atomic locks (the home page changes use `Cache::lock()`)

The `exif` extension is optional. When it is present, JPEG uploads are rotated according to their EXIF orientation
before the metadata is dropped.

## 1. Install via Composer

```bash
composer require j87nl/laravel-cms
```

The service provider `J87NL\Cms\CmsServiceProvider` is auto-discovered. The package pulls in
`tranquil-tools/laravel-form-builder`, which the block types use to describe their fields, and `laravel/mcp` for
the [MCP tools](mcp-tools.md).

## 2. Create the media table

Media is stored with `spatie/laravel-medialibrary`. If your app does not have its `media` table yet, publish the
migration of that package:

```bash
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-migrations"
```

## 3. Publish the config and migrate

```bash
php artisan vendor:publish --tag=cms-config
php artisan migrate
```

The package loads its own migrations, so `migrate` creates the `cms_*` tables without publishing them. See
[Configuration](configuration.md) for every config key.

Images are stored on the `public` disk by default (`cms.media.disk`). Link it into `public/` so their URLs work:

```bash
php artisan storage:link
```

## 4. Point the config at your layout

Set `layout` in `config/cms.php` to your public Blade layout. The layout yields `title`, `head` and `content`:

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    @yield('head')
</head>
<body>
    <x-cms::menu key="main" />

    <main>@yield('content')</main>

    <x-cms::menu key="footer" />
</body>
</html>
```

The default, `cms::layouts.default`, is the same layout without any styling. See [Rendering](rendering.md).

## 5. Define the permission gates

The package defines no gates. The [MCP tools](mcp-tools.md) check each CMS permission with
`Gate::forUser($user)->allows(...)`, so every tool refuses until your app defines a gate per permission. In the
`boot()` method of a service provider:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use J87NL\Cms\CmsPermission;

foreach (CmsPermission::names() as $permission) {
    Gate::define($permission, fn (User $user): bool => $user->is_admin);
}
```

Replace `$user->is_admin` with your own role check. See [Permissions](permissions.md).

## 6. Run a queue worker and the scheduler

Image conversions are queued by the media library, and the contact form mail is queued with `Mail::queue()`.
Run a queue worker in production:

```bash
php artisan queue:work
```

The package schedules `cms:publish-scheduled` every minute. Run the Laravel scheduler, for example with a cron
entry for `php artisan schedule:run`. See [Scheduling](scheduling.md).

## 7. Remove the static robots.txt

The package serves `robots.txt` itself. A fresh Laravel app ships `public/robots.txt`, which the web server
returns before the request reaches Laravel. Delete that file so the package's version is served.

## 8. Hide the contact form honeypot

The package ships no CSS. The contact form hides its honeypot field with the class `cms-contact__trap`, so your
site stylesheet must hide it:

```css
.cms-contact__trap { position: absolute; left: -10000px; }
```

## 9. Flush the page cache on deploy

Cached pages are only dropped by content changes. Add this to your deploy script, so a changed layout or view
reaches visitors:

```bash
php artisan cms:flush-page-cache
```

## Optional: publish the views

```bash
php artisan vendor:publish --tag=cms-views
```

This copies the views to `resources/views/vendor/cms`. See [Rendering](rendering.md#customizing-views).
