# Blade components

The package registers its class components under the `cms` prefix (`J87NL\Cms\View\Components`). Use them in your
layout or in your own views.

## Menu

```blade
<x-cms::menu key="main" />
```

| Attribute | Type | Purpose |
|---|---|---|
| `key` | `string` | The menu key, for example `main` or `footer` |

Renders `<nav class="cms-menu cms-menu--{key}">` with nested `ul.cms-menu__list` lists. The link of the current path
gets `aria-current="page"`. Renders nothing when the menu has no visible items, and nothing on the not-found page.
See [Menus](menus.md).

## Image

```blade
<x-cms::image :asset="$asset" sizes="(min-width: 768px) 50vw, 100vw" :lazy="true" :cover="false" class="hero" />
```

| Attribute | Type | Default | Purpose |
|---|---|---|---|
| `asset` | `MediaAsset` | - | The image |
| `sizes` | `string` | `'100vw'` | The `sizes` attribute, written when there is a `srcset` |
| `lazy` | `bool` | `true` | `loading="lazy"`, or `eager` when `false` |
| `cover` | `bool` | `false` | Add the focal point as inline `object-position` style |

Renders an `img` with the largest WebP conversion as `src`, a `srcset` of all conversions (when there are at least
two). While no conversion exists yet, it falls back to the original file without a `srcset`. It also writes the
original `width` and `height`, the asset's alt text and `decoding="async"`. Other attributes, such as `class`, are
passed on to the `img`. Renders nothing when the asset has no file.

The inline `object-position` style is why the Content-Security-Policy allows `style-src-attr 'unsafe-inline'`. With
`cover`, give the image a fixed box and `object-fit: cover` in your stylesheet.

## Blocks

```blade
<x-cms::blocks :page="$page" />
```

Renders the visible blocks of a page inside `<div class="cms-blocks">`. The `cms::page` view already does this; use
the component when you render a page in your own view.

## Seo

```blade
<x-cms::seo :page="$page" :preview="false" />
```

| Attribute | Type | Default | Purpose |
|---|---|---|---|
| `page` | `Page` | - | The page |
| `preview` | `bool` | `false` | Add `noindex, nofollow` |

Renders the meta description, robots, canonical, Open Graph and Twitter tags and the JSON-LD script. It belongs in the
`head`. See [SEO tags and JSON-LD](seo.md).
