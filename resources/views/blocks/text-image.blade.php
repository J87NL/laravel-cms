<section class="cms-block cms-block--text-image cms-block--image-{{ $position }}">
    @if ($asset)
        <figure class="cms-block__media">
            <x-cms::image :asset="$asset" :lazy="$lazy" :cover="true" sizes="(min-width: 768px) 50vw, 100vw" />

            @if ($asset->caption)
                <figcaption>{{ $asset->caption }}</figcaption>
            @endif
        </figure>
    @endif

    <div class="cms-block__text cms-prose">
        {!! $html !!}
    </div>
</section>
