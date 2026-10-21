@if ($src)
    <img
        src="{{ $src }}"
        @if ($srcset) srcset="{{ $srcset }}" sizes="{{ $sizes }}" @endif
        @if ($width > 0 && $height > 0) width="{{ $width }}" height="{{ $height }}" @endif
        alt="{{ $alt ?? $asset->alt }}"
        loading="{{ $lazy ? 'lazy' : 'eager' }}"
        decoding="async"
        @if ($cover) style="object-position: {{ $asset->objectPosition() }}" @endif
        {{ $attributes }}
    >
@endif
