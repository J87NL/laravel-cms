<ul class="cms-menu__list">
    @foreach ($items as $item)
        <li class="cms-menu__item">
            <a class="cms-menu__link" href="{{ $item['href'] }}"@if ($item['current']) aria-current="page"@endif>{{ $item['label'] }}</a>

            @if ($item['children'] !== [])
                @include('cms::menu.items', ['items' => $item['children']])
            @endif
        </li>
    @endforeach
</ul>
