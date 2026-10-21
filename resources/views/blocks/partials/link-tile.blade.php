@if ($tile['asset'])
    <x-cms::image :asset="$tile['asset']" :lazy="$lazy" :alt="$tile['name']" class="cms-link-tiles__image" sizes="12rem" />
@else
    <span class="cms-link-tiles__name">{{ $tile['name'] }}</span>
@endif
