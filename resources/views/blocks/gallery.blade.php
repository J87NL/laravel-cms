@if ($assets !== [])
    <section class="cms-block cms-block--gallery">
        <ul class="cms-gallery">
            @foreach ($assets as $asset)
                <li class="cms-gallery__item">
                    <figure>
                        <x-cms::image :asset="$asset" :lazy="$lazy" :cover="true" sizes="(min-width: 768px) 33vw, 50vw" />

                        @if ($asset->caption)
                            <figcaption>{{ $asset->caption }}</figcaption>
                        @endif
                    </figure>
                </li>
            @endforeach
        </ul>
    </section>
@endif
