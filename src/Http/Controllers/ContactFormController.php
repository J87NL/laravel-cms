<?php

declare(strict_types=1);

namespace J87NL\Cms\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Contact\ContactFormGuard;
use J87NL\Cms\Contact\ContactFormLimiter;
use J87NL\Cms\Contact\ContactFormRejection;
use J87NL\Cms\Contact\ContactFormSpamFilter;
use J87NL\Cms\Contact\ContactFormState;
use J87NL\Cms\Contact\ContactFormVerdict;
use J87NL\Cms\Mail\ContactFormSubmitted;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Models\SiteSettings;
use J87NL\Cms\Pages\PageVisibility;
use J87NL\Cms\Rendering\PageResponse;

class ContactFormController extends Controller
{
    public const string BLOCK_TYPE = 'contact_form';

    public function __construct(
        private readonly ContactFormGuard $guard,
        private readonly ContactFormLimiter $limiter,
        private readonly ContactFormSpamFilter $spamFilter,
        private readonly BlockRegistry $registry,
        private readonly PageVisibility $visibility,
        private readonly PageResponse $pageResponse,
    ) {}

    public function store(Request $request, string $block): Response|RedirectResponse
    {
        $contactBlock = Block::query()
            ->with('page')
            ->where('type', self::BLOCK_TYPE)
            ->where('is_visible', true)
            ->find((int) $block);

        $page = $contactBlock?->page;

        if (is_null($contactBlock) || ! $this->registry->has(self::BLOCK_TYPE) || ! $page instanceof Page || ! $this->visibility->isPublic($page)) {
            abort(404);
        }

        if ($this->limiter->tooManyFromAddress($request)) {
            return $this->tooMany($request, $page, $contactBlock, $this->limiter->retryAfterForAddress($request));
        }

        $sendAllowedIn = $this->limiter->secondsUntilSendAllowed($request);

        if (! is_null($sendAllowedIn)) {
            return $this->tooMany($request, $page, $contactBlock, $sendAllowedIn, __('cms::blocks.contact_form.too_fast'));
        }

        $verdict = $this->guard->verdict($request, $contactBlock->id);

        if ($verdict !== ContactFormVerdict::Human) {
            $this->limiter->hitAddress($request);
        }

        if ($verdict === ContactFormVerdict::Bot) {
            $this->logRejection($contactBlock, ContactFormRejection::BotCheck);

            return $this->sent($page, $contactBlock);
        }

        if ($verdict === ContactFormVerdict::Expired) {
            return $this->failed($request, $page, $contactBlock, new MessageBag([
                'form' => __('cms::blocks.contact_form.expired'),
            ]), 422);
        }

        $validator = Validator::make($request->only(['name', 'email', 'message']), [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'message' => ['required', 'string', 'max:5000'],
        ], [], [
            'name' => __('cms::blocks.contact_form.name'),
            'email' => __('cms::blocks.contact_form.email'),
            'message' => __('cms::blocks.contact_form.message'),
        ]);

        if ($validator->fails()) {
            return $this->failed($request, $page, $contactBlock, $validator->errors(), 422);
        }

        $settings = SiteSettings::current();
        $recipient = trim((string) $settings->contact_recipient) ?: trim((string) config('cms.contact.recipient'));

        if ($recipient === '') {
            return $this->failed($request, $page, $contactBlock, new MessageBag([
                'form' => __('cms::blocks.contact_form.unavailable'),
            ]), 503);
        }

        $validated = $validator->validated();

        $mail = new ContactFormSubmitted(
            senderName: trim((string) $validated['name']),
            senderEmail: trim((string) $validated['email']),
            body: trim((string) $validated['message']),
            siteName: $settings->siteName(),
            pageTitle: $page->title,
            pageUrl: url($page->url()),
        );

        $rejection = $this->spamFilter->rejection($mail, $recipient);

        if (! is_null($rejection)) {
            $this->limiter->hitAddress($request);
            $this->logRejection($contactBlock, $rejection);

            return $this->sent($page, $contactBlock);
        }

        if (! $this->limiter->claimOverall()) {
            return $this->tooMany($request, $page, $contactBlock, $this->limiter->retryAfterOverall());
        }

        Mail::to($recipient)->queue($mail);

        $this->limiter->hitAddress($request);
        $this->limiter->hitSend($request);

        return $this->sent($page, $contactBlock);
    }

    private function tooMany(Request $request, Page $page, Block $block, int $retryAfter, ?string $message = null): Response
    {
        return $this->failed($request, $page, $block, new MessageBag([
            'form' => $message ?? __('cms::blocks.contact_form.too_many'),
        ]), 429)->header('Retry-After', (string) $retryAfter);
    }

    private function logRejection(Block $block, ContactFormRejection $rejection): void
    {
        Log::info('The contact form rejected a submission as spam.', [
            'block' => $block->id,
            'reason' => $rejection->value,
        ]);
    }

    private function sent(Page $page, Block $block): RedirectResponse
    {
        return redirect(sprintf('%s?%s=%d#contact-%d', $page->url(), rawurlencode(ContactFormState::sentQuery()), $block->id, $block->id), 303);
    }

    private function failed(Request $request, Page $page, Block $block, MessageBag $errors, int $status): Response
    {
        ContactFormState::remember($request, new ContactFormState($block->id, $errors, [
            'name' => (string) $request->input('name', ''),
            'email' => (string) $request->input('email', ''),
            'message' => (string) $request->input('message', ''),
        ]));

        return $this->pageResponse->make($page)->setStatusCode($status);
    }
}
