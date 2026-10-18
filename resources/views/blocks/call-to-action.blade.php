<section class="cms-block cms-block--call-to-action">
    <h2 class="cms-cta__title">{{ $title }}</h2>

    @if ($text !== '')
        <p class="cms-cta__text">{{ $text }}</p>
    @endif

    @if ($url)
        <a class="cms-cta__button" href="{{ $url }}">{{ $buttonLabel }}</a>
    @endif
</section>
