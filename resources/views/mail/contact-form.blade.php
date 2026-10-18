{{ __('cms::blocks.contact_form.mail_intro', ['url' => $pageUrl]) }}

{{ __('cms::blocks.contact_form.name') }}: {{ $senderName }}
{{ __('cms::blocks.contact_form.email') }}: {{ $senderEmail }}

{{ $body }}
