@if ($asset)
    <figure class="cms-block cms-block--image">
        <x-cms::image :asset="$asset" :lazy="$lazy" sizes="(min-width: 1024px) 960px, 100vw" />

        @if ($asset->caption)
            <figcaption>{{ $asset->caption }}</figcaption>
        @endif
    </figure>
@endif
