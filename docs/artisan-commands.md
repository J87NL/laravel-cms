# Artisan commands

## `cms:publish-scheduled`

Publishes and unpublishes pages whose planned moment has passed:

```bash
php artisan cms:publish-scheduled
```

The command has no options. It prints a line per changed page and a warning per refused change. It exits with a
failure code when a page failed with an unexpected exception.

The package schedules it every minute, without overlapping, while `cms.scheduling.enabled` is `true`. See
[Scheduling](scheduling.md).

## `cms:flush-page-cache`

Drops every cached public page:

```bash
php artisan cms:flush-page-cache
```

The command has no options. Content changes flush the cache by themselves, but a changed layout, view or translation
does not. Run this command after every deploy. See [Page cache](page-cache.md).

## Publishing files

| Command | Copies |
|---|---|
| `php artisan vendor:publish --tag=cms-config` | `config/cms.php` |
| `php artisan vendor:publish --tag=cms-views` | The views, to `resources/views/vendor/cms` |

The migrations are loaded from the package and are not published. The published config may hold only the keys you
change: missing keys, also nested ones, fall back to the package defaults, see [Configuration](configuration.md).
