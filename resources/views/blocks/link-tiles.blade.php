@if ($tiles !== [])
    <section class="cms-block cms-block--link-tiles" @if ($title !== '') aria-labelledby="{{ $headingId }}" @else aria-label="{{ __('cms::public.link_tiles.label') }}" @endif>
        @if ($title !== '')
            <h2 class="cms-link-tiles__title" id="{{ $headingId }}">{{ $title }}</h2>
        @endif

        <ul class="cms-link-tiles">
            @foreach ($tiles as $tile)
                <li class="cms-link-tiles__item">
                    @if ($tile['url'])
                        <a class="cms-link-tiles__tile" href="{{ $tile['url'] }}" target="_blank" rel="{{ $rel }}" aria-label="{{ __('cms::blocks.link_tiles.link_label', ['name' => $tile['name']]) }}">
                            @include('cms::blocks.partials.link-tile', ['tile' => $tile, 'lazy' => $lazy])
                        </a>
                    @else
                        <div class="cms-link-tiles__tile">
                            @include('cms::blocks.partials.link-tile', ['tile' => $tile, 'lazy' => $lazy])
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
