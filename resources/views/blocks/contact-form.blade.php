<section class="cms-block cms-block--contact-form" id="{{ $anchor }}">
    @if ($title !== '')
        <h2 class="cms-contact__title">{{ $title }}</h2>
    @endif

    @if ($sent)
        <p class="cms-contact__success" role="status">{{ $successMessage }}</p>
    @else
        @if ($intro !== '')
            <p class="cms-contact__intro">{{ $intro }}</p>
        @endif

        @if ($errors->isNotEmpty())
            <div class="cms-contact__errors" role="alert">
                <p>{{ __('cms::blocks.contact_form.has_errors') }}</p>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form class="cms-contact" method="post" action="{{ $action }}">
            <p class="cms-contact__field">
                <label for="{{ $anchor }}-name">{{ __('cms::blocks.contact_form.name') }}</label>
                <input id="{{ $anchor }}-name" type="text" name="name" value="{{ $old['name'] ?? '' }}" autocomplete="name" maxlength="120" required>
            </p>

            <p class="cms-contact__field">
                <label for="{{ $anchor }}-email">{{ __('cms::blocks.contact_form.email') }}</label>
                <input id="{{ $anchor }}-email" type="email" name="email" value="{{ $old['email'] ?? '' }}" autocomplete="email" maxlength="254" required>
            </p>

            <p class="cms-contact__field">
                <label for="{{ $anchor }}-message">{{ __('cms::blocks.contact_form.message') }}</label>
                <textarea id="{{ $anchor }}-message" name="message" rows="6" maxlength="5000" required>{{ $old['message'] ?? '' }}</textarea>
            </p>

            <p class="cms-contact__trap" aria-hidden="true">
                <label for="{{ $anchor }}-website">{{ __('cms::blocks.contact_form.trap') }}</label>
                <input id="{{ $anchor }}-website" type="text" name="{{ \J87NL\Cms\Contact\ContactFormGuard::HONEYPOT }}" tabindex="-1" autocomplete="off">
            </p>

            <input type="hidden" name="{{ \J87NL\Cms\Contact\ContactFormGuard::TIMESTAMP }}" value="{{ $token }}">

            @if ($privacyUrl)
                <p class="cms-contact__privacy">
                    {{ __('cms::blocks.contact_form.privacy_before') }}
                    <a href="{{ $privacyUrl }}">{{ __('cms::blocks.contact_form.privacy_link') }}</a>.
                </p>
            @endif

            <p class="cms-contact__actions">
                <button type="submit" class="cms-contact__button">{{ $buttonLabel }}</button>
            </p>
        </form>
    @endif
</section>
