@if ($seo->description !== '')
    <meta name="description" content="{{ $seo->description }}">
@endif
@if (! is_null($seo->robots))
    <meta name="robots" content="{{ $seo->robots }}">
@endif
<link rel="canonical" href="{{ $seo->canonical }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $seo->siteName }}">
<meta property="og:locale" content="{{ $seo->locale }}">
<meta property="og:title" content="{{ $seo->title }}">
@if ($seo->description !== '')
    <meta property="og:description" content="{{ $seo->description }}">
@endif
<meta property="og:url" content="{{ $seo->canonical }}">
@if (! is_null($seo->image))
    <meta property="og:image" content="{{ $seo->image['url'] }}">
    @if ($seo->image['width'] > 0 && $seo->image['height'] > 0)
        <meta property="og:image:width" content="{{ $seo->image['width'] }}">
        <meta property="og:image:height" content="{{ $seo->image['height'] }}">
    @endif
    <meta property="og:image:alt" content="{{ $seo->image['alt'] }}">
    <meta name="twitter:card" content="summary_large_image">
@else
    <meta name="twitter:card" content="summary">
@endif
<meta name="twitter:title" content="{{ $seo->title }}">
@if ($seo->description !== '')
    <meta name="twitter:description" content="{{ $seo->description }}">
@endif
<script type="application/ld+json">{!! $seo->jsonLd() !!}</script>
