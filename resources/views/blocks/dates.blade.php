<section class="cms-block cms-block--dates">
    @if ($title !== '')
        <h2 class="cms-dates__heading">{{ $title }}</h2>
    @endif

    @if ($location !== '')
        <p class="cms-dates__location">{{ $location }}</p>
    @endif

    <ol class="cms-dates">
        @foreach ($items as $item)
            <li class="cms-dates__item">
                <time class="cms-dates__date" datetime="{{ $item['date'] }}">{{ $item['carbon']->translatedFormat('l j F Y') }}</time>
                <span class="cms-dates__title">{{ $item['title'] }}</span>

                @if ($item['description'] !== '')
                    <p class="cms-dates__description">{{ $item['description'] }}</p>
                @endif
            </li>
        @endforeach
    </ol>
</section>
