<?php

declare(strict_types=1);

namespace J87NL\Cms\Blocks\Types;

use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use J87NL\Cms\Blocks\AbstractBlockType;
use J87NL\Cms\Blocks\BlockContext;
use J87NL\Cms\Contact\ContactFormGuard;
use J87NL\Cms\Contact\ContactFormState;
use J87NL\Cms\Models\SiteSettings;
use TranquilTools\FormBuilder\Fields\Text;
use TranquilTools\FormBuilder\Fields\Textarea;

class ContactFormBlock extends AbstractBlockType
{
    public function __construct(
        private readonly ContactFormGuard $guard,
    ) {}

    public function key(): string
    {
        return 'contact_form';
    }

    public function view(): string
    {
        return 'cms::blocks.contact-form';
    }

    public function fields(): array
    {
        return [
            Text::make('title')
                ->label(__('cms::blocks.contact_form.fields.title'))
                ->rules([
                    'nullable',
                    'string',
                    'max:160',
                ]),

            Textarea::make('intro')
                ->label(__('cms::blocks.contact_form.fields.intro'))
                ->rules([
                    'nullable',
                    'string',
                    'max:1000',
                ]),

            Text::make('button_label')
                ->label(__('cms::blocks.contact_form.fields.button_label'))
                ->help(__('cms::blocks.contact_form.help.button_label'))
                ->rules([
                    'nullable',
                    'string',
                    'max:80',
                ]),

            Textarea::make('success_message')
                ->label(__('cms::blocks.contact_form.fields.success_message'))
                ->help(__('cms::blocks.contact_form.help.success_message'))
                ->rules([
                    'nullable',
                    'string',
                    'max:500',
                ]),
        ];
    }

    public function prepare(array $data): array
    {
        return [
            'title' => trim($this->string($data, 'title')),
            'intro' => trim($this->string($data, 'intro')),
            'button_label' => trim($this->string($data, 'button_label')),
            'success_message' => trim($this->string($data, 'success_message')),
        ];
    }

    public function viewData(array $data, BlockContext $context): array
    {
        $request = app(Request::class);
        $blockId = $context->block->id ?? 0;
        $state = ContactFormState::for($request, $blockId);

        return [
            'title' => $this->string($data, 'title'),
            'intro' => $this->string($data, 'intro'),
            'buttonLabel' => $this->string($data, 'button_label') ?: __('cms::blocks.contact_form.default_button'),
            'successMessage' => $this->string($data, 'success_message') ?: __('cms::blocks.contact_form.default_success'),
            'anchor' => sprintf('contact-%d', $blockId),
            'action' => route('cms.contact', ['block' => $blockId]),
            'token' => $this->guard->token($blockId),
            'sent' => ContactFormState::sent($request, $blockId),
            'errors' => $state->errors ?? new MessageBag,
            'old' => $state->old ?? [],
            'privacyUrl' => SiteSettings::current()->privacyUrl(),
        ];
    }

    public function toPlainText(array $data): string
    {
        return trim(sprintf('%s %s', $this->string($data, 'title'), $this->string($data, 'intro')));
    }
}
