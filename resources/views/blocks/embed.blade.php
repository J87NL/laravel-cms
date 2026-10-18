@if ($url)
    <div class="cms-block cms-block--embed">
        <iframe
            src="{{ $url }}"
            title="{{ $title }}"
            loading="lazy"
            referrerpolicy="strict-origin-when-cross-origin"
            allow="fullscreen; picture-in-picture"
            allowfullscreen
            sandbox="allow-scripts allow-same-origin allow-popups allow-presentation"
        ></iframe>
    </div>
@endif
