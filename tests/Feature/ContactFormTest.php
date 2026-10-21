<?php

declare(strict_types=1);

use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use J87NL\Cms\Actions\SaveSiteSettings;
use J87NL\Cms\Contact\ContactFormGuard;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Mail\ContactFormSubmitted;
use J87NL\Cms\Models\Block;

beforeEach(function (): void {
    Mail::fake();

    $privacy = makePage([
        'title' => 'Privacy',
        'slug' => 'privacy',
    ]);

    app(SaveSiteSettings::class)->handle([
        'site_name' => 'Johan',
        'contact_recipient' => 'contact@j87.nl',
        'privacy_page_id' => $privacy->id,
    ]);

    $this->page = makePage([
        'title' => 'Contact',
        'slug' => 'contact',
    ]);

    $this->block = addBlock($this->page, 'contact_form', [
        'title' => 'Mail me',
    ]);
});

/**
 * @return array<string, string>
 */
function contactSubmission(Block $block, array $overrides = []): array
{
    test()->travel(-10)->seconds();
    $token = app(ContactFormGuard::class)->token($block->id);
    test()->travelBack();

    return [
        'name' => 'Visitor',
        'email' => 'visitor@example.org',
        'message' => 'Hello there',
        ContactFormGuard::TIMESTAMP => $token,
        ...$overrides,
    ];
}

test('the form renders with a honeypot, a signed timestamp and the privacy link', function (): void {
    $this->get('/contact')
        ->assertOk()
        ->assertSee('Mail me')
        ->assertSee(sprintf('action="%s"', route('cms.contact', ['block' => $this->block->id])), false)
        ->assertSee(sprintf('name="%s"', ContactFormGuard::HONEYPOT), false)
        ->assertSee(sprintf('name="%s"', ContactFormGuard::TIMESTAMP), false)
        ->assertSee('href="/privacy"', false);
});

test('a submission mails the recipient with the visitor as reply-to and sets no cookies', function (): void {
    $response = $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))
        ->assertStatus(303)
        ->assertRedirect(sprintf('/contact?verzonden=%1$d#contact-%1$d', $this->block->id));

    expect($response->headers->getCookies())->toBeEmpty();

    Mail::assertQueued(ContactFormSubmitted::class, fn (ContactFormSubmitted $mail): bool => $mail->hasTo('contact@j87.nl')
        && $mail->hasReplyTo('visitor@example.org', 'Visitor')
        && $mail->body === 'Hello there');

    $this->get(sprintf('/contact?verzonden=%d', $this->block->id))
        ->assertSee(__('cms::blocks.contact_form.default_success'));
});

test('invalid input shows the errors and keeps the input', function (): void {
    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, [
        'email' => 'not-an-email',
    ]))
        ->assertStatus(422)
        ->assertSee(__('cms::blocks.contact_form.has_errors'))
        ->assertSee('value="Visitor"', false)
        ->assertSee('Hello there');

    Mail::assertNothingOutgoing();
});

test('bots filling the honeypot, posting too fast or forging the token get no mail sent', function (Closure $submission): void {
    $this->post(route('cms.contact', ['block' => $this->block->id]), $submission($this->block))
        ->assertStatus(303);

    Mail::assertNothingOutgoing();
})->with([
    'honeypot' => [fn (Block $block) => contactSubmission($block, [ContactFormGuard::HONEYPOT => 'https://spam.example'])],
    'too fast' => [fn (Block $block) => contactSubmission($block, [ContactFormGuard::TIMESTAMP => app(ContactFormGuard::class)->token($block->id)])],
    'forged' => [fn (Block $block) => contactSubmission($block, [ContactFormGuard::TIMESTAMP => sprintf('%d.forged', now()->subMinute()->getTimestamp())])],
    'missing' => [fn (Block $block) => array_diff_key(contactSubmission($block), [ContactFormGuard::TIMESTAMP => true])],
]);

test('submissions are rate limited per address', function (): void {
    foreach (range(1, 5) as $attempt) {
        $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))->assertStatus(303);
    }

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertSee(__('cms::blocks.contact_form.too_many'));

    Mail::assertQueuedCount(5);
});

test('typos do not count toward the limit but bot submissions do', function (): void {
    foreach (range(1, 6) as $attempt) {
        $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, [
            'email' => 'typo',
        ]))->assertStatus(422);
    }

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))->assertStatus(303);

    foreach (range(1, 4) as $attempt) {
        $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, [
            ContactFormGuard::HONEYPOT => 'https://spam.example',
        ]))->assertStatus(303);
    }

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))->assertStatus(429);

    Mail::assertQueuedCount(1);
});

test('a global hourly cap stops mail from many addresses', function (): void {
    config()->set('cms.contact.global_per_hour', 3);

    foreach (range(1, 3) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => sprintf('10.0.0.%d', $attempt)])
            ->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))
            ->assertStatus(303);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
        ->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertSee(__('cms::blocks.contact_form.too_many'));

    Mail::assertQueuedCount(3);

    $this->travel(61)->minutes();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
        ->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))
        ->assertStatus(303);
});

test('a flood of invalid posts is still bounded per minute', function (): void {
    foreach (range(1, 30) as $attempt) {
        $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, [
            'email' => 'typo',
        ]))->assertStatus(422);
    }

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))->assertStatus(429);

    $submission = contactSubmission($this->block);

    $this->travel(61)->seconds();

    $this->post(route('cms.contact', ['block' => $this->block->id]), $submission)->assertStatus(303);
});

test('a disabled contact block type accepts no submissions', function (): void {
    config()->set('cms.blocks.disabled', ['contact_form']);

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))->assertNotFound();

    Mail::assertNothingOutgoing();
});

test('a hidden contact block accepts no submissions', function (): void {
    $this->block->is_visible = false;
    $this->block->save();

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))->assertNotFound();

    Mail::assertNothingOutgoing();
});

test('the plain text mail keeps quotes and ampersands and its headers stay on one line', function (): void {
    $mailer = (new MailManager(app()))->mailer('array');

    $mailer->to('contact@j87.nl')->send(new ContactFormSubmitted(
        senderName: "Jan & Piet\r\nBcc: evil@example.org",
        senderEmail: 'visitor@example.org',
        body: "It's <b>Tom</b> & Jerry",
        siteName: "Johan's site",
        pageTitle: "Contact\r\nBcc: evil@example.org",
        pageUrl: 'https://j87.nl/contact',
    ));

    $message = $mailer->getSymfonyTransport()->messages()->first()->getOriginalMessage();
    $headers = $message->getHeaders()->toString();

    expect($message->getTextBody())
        ->toContain("It's <b>Tom</b> & Jerry")
        ->toContain('Jan & Piet')
        ->not->toContain('&amp;')
        ->not->toContain('&#039;')
        ->and($headers)->not->toMatch('/^Bcc:/mi')
        ->and($message->getSubject())->toBe(__('cms::blocks.contact_form.mail_subject', [
            'site' => "Johan's site",
            'page' => 'Contact Bcc: evil@example.org',
        ]))
        ->and($message->getReplyTo()[0]->getName())->toBe('Jan & Piet Bcc: evil@example.org');
});

test('only visible contact blocks on public pages accept submissions', function (): void {
    $draft = makePage([
        'title' => 'Draft',
        'slug' => 'draft',
    ], PageStatus::Draft);
    $hidden = addBlock($draft, 'contact_form', []);
    $text = addBlock($this->page, 'rich_text', ['html' => '<p>Text</p>']);

    $this->post(route('cms.contact', ['block' => $hidden->id]), contactSubmission($hidden))->assertNotFound();
    $this->post(route('cms.contact', ['block' => $text->id]), contactSubmission($text))->assertNotFound();
});

test('without a recipient the form says it cannot send', function (): void {
    app(SaveSiteSettings::class)->handle([
        'contact_recipient' => null,
    ]);

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))
        ->assertStatus(503)
        ->assertSee(__('cms::blocks.contact_form.unavailable'));

    Mail::assertNothingOutgoing();
});

test('a genuine but expired token asks to try again with a fresh token and keeps the input', function (): void {
    $this->travel(-((int) config('cms.contact.max_seconds')) - 60)->seconds();
    $expired = app(ContactFormGuard::class)->token($this->block->id);
    $this->travelBack();

    $response = $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, [
        ContactFormGuard::TIMESTAMP => $expired,
    ]))
        ->assertStatus(422)
        ->assertSee(__('cms::blocks.contact_form.expired'))
        ->assertSee('value="Visitor"', false);

    expect($response->getContent())->not->toContain($expired);

    Mail::assertNothingOutgoing();
});

test('the success query parameter can be configured', function (): void {
    config()->set('cms.contact.sent_query', 'sent');

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))
        ->assertStatus(303)
        ->assertRedirect(sprintf('/contact?sent=%1$d#contact-%1$d', $this->block->id));

    $this->get(sprintf('/contact?sent=%d', $this->block->id))
        ->assertSee(__('cms::blocks.contact_form.default_success'));

    $this->get(sprintf('/contact?verzonden=%d', $this->block->id))
        ->assertDontSee(__('cms::blocks.contact_form.default_success'));
});

test('a resubmit right after an expired or invalid post is accepted', function (array $overrides): void {
    $this->travel(-((int) config('cms.contact.max_seconds')) - 60)->seconds();
    $expired = app(ContactFormGuard::class)->token($this->block->id);
    $this->travelBack();

    $first = $overrides === [] ? [ContactFormGuard::TIMESTAMP => $expired] : $overrides;

    $response = $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, $first))
        ->assertStatus(422);

    preg_match(sprintf('/name="%s" value="([^"]+)"/', ContactFormGuard::TIMESTAMP), (string) $response->getContent(), $matches);

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, [
        ContactFormGuard::TIMESTAMP => $matches[1] ?? '',
    ]))->assertStatus(303);

    Mail::assertQueuedCount(1);
})->with([
    'expired' => [[]],
    'invalid' => [['email' => 'typo']],
]);
