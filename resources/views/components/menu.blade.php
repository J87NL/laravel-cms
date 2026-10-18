@if ($items !== [])
    <nav class="cms-menu cms-menu--{{ $key }}" aria-label="{{ __('cms::public.menus.'.$key) }}">
        @include('cms::menu.items', ['items' => $items])
    </nav>
@endif
