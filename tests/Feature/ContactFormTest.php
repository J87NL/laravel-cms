<?php

declare(strict_types=1);

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

    Mail::assertSent(ContactFormSubmitted::class, fn (ContactFormSubmitted $mail): bool => $mail->hasTo('contact@j87.nl')
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

    Mail::assertNothingSent();
});

test('bots filling the honeypot, posting too fast or forging the token get no mail sent', function (Closure $submission): void {
    $this->post(route('cms.contact', ['block' => $this->block->id]), $submission($this->block))
        ->assertStatus(303);

    Mail::assertNothingSent();
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

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))->assertStatus(429);
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

    Mail::assertNothingSent();
});
