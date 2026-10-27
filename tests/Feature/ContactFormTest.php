<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use J87NL\Cms\Actions\SaveSiteSettings;
use J87NL\Cms\Contact\ContactFormGuard;
use J87NL\Cms\Contact\ContactFormLimiter;
use J87NL\Cms\Contact\Turnstile;
use J87NL\Cms\Contact\TurnstileKeys;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Mail\ContactFormSubmitted;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\SiteSettings;

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

function useTurnstile(?string $siteKey, ?string $secretKey): void
{
    app(SaveSiteSettings::class)->handle([
        'contact_turnstile_site_key' => $siteKey,
        'contact_turnstile_secret_key' => $secretKey,
    ]);
}

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
        $submission = contactSubmission($this->block);

        $this->travel($attempt * 61)->seconds();

        $this->post(route('cms.contact', ['block' => $this->block->id]), $submission)->assertStatus(303);
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

test('sent messages are limited per minute per address and block', function (): void {
    $other = addBlock($this->page, 'contact_form', [
        'title' => 'Second form',
    ]);

    foreach (range(1, 3) as $attempt) {
        $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))->assertStatus(303);
    }

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertSee(__('cms::blocks.contact_form.too_fast'));

    $this->post(route('cms.contact', ['block' => $other->id]), contactSubmission($other))->assertStatus(303);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
        ->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))
        ->assertStatus(303);

    $submission = contactSubmission($this->block);

    $this->travel(61)->seconds();

    $this->post(route('cms.contact', ['block' => $this->block->id]), $submission)->assertStatus(303);

    Mail::assertQueuedCount(6);
});

test('rejected and invalid submissions do not count toward the per minute limit', function (): void {
    foreach (range(1, 3) as $attempt) {
        $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, [
            'email' => 'typo',
        ]))->assertStatus(422);
    }

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, [
        'message' => 'https://spam.example',
    ]))->assertStatus(303);

    foreach (range(1, 3) as $attempt) {
        $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))->assertStatus(303);
    }

    Mail::assertQueuedCount(3);
});

test('an app can replace the named send limiter', function (): void {
    RateLimiter::for(ContactFormLimiter::NAMED_LIMITER, fn (): Limit => Limit::perHour(1)->by('everyone'));

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))->assertStatus(303);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
        ->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))
        ->assertStatus(429);

    Mail::assertQueuedCount(1);
});

test('spam content gets the sent redirect but no mail, and the log holds no content', function (string $message): void {
    config()->set('cms.contact.blocked_keywords', [
        'Casino',
    ]);

    Log::spy();

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, [
        'message' => $message,
    ]))
        ->assertStatus(303)
        ->assertRedirect(sprintf('/contact?verzonden=%1$d#contact-%1$d', $this->block->id));

    Mail::assertNothingOutgoing();

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $text, array $context): bool => $context['block'] === $this->block->id
            && ! str_contains(json_encode($context), 'example'));
})->with([
    'only a link' => ['https://spam.example/offer'],
    'only links and punctuation' => ['www.spam.example - https://spam.example/x !'],
    'too many links' => ['See https://a.example and https://b.example and www.c.example'],
    'a blocked keyword in any case' => ['Win big at our cASINO today'],
    'links glued together with commas' => ['Hi http://a.example,http://b.example,http://c.example'],
    'invalid UTF-8' => ["Hello \xC3\x28 there"],
]);

test('any number of links is sent when the link limit is switched off', function (): void {
    config()->set('cms.contact.max_links', null);

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, [
        'message' => 'See https://a.example, https://b.example and https://c.example',
    ]))->assertStatus(303);

    Mail::assertQueuedCount(1);
});

test('messages with a few links or without blocked keywords are sent', function (): void {
    config()->set('cms.contact.blocked_keywords', [
        'casino',
    ]);

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, [
        'message' => 'Is the market on https://www.example.org/markt still on? See http://www.example.net too.',
    ]))->assertStatus(303);

    Mail::assertQueuedCount(1);
});

test('spam rejections count toward the hourly address limit', function (): void {
    foreach (range(1, 5) as $attempt) {
        $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, [
            'message' => 'https://spam.example',
        ]))->assertStatus(303);
    }

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))->assertStatus(429);

    Mail::assertNothingOutgoing();
});

test('rspamd rejects by score or action and the composed message reaches it', function (array $answer): void {
    config()->set('cms.contact.rspamd.url', 'http://127.0.0.1:11333/checkv2');

    Http::fake([
        '127.0.0.1:11333/*' => Http::response($answer),
    ]);

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))->assertStatus(303);

    Mail::assertNothingOutgoing();

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'http://127.0.0.1:11333/checkv2'
        && str_contains($request->body(), 'From: Visitor <visitor@example.org>')
        && str_contains($request->body(), 'To: contact@j87.nl')
        && str_contains($request->body(), 'Hello there'));
})->with([
    'score at the threshold' => [['score' => 6.0, 'action' => 'add header']],
    'reject action' => [['score' => 3.0, 'action' => 'reject']],
]);

test('rspamd below the threshold lets the message through', function (): void {
    config()->set('cms.contact.rspamd.url', 'http://127.0.0.1:11333/checkv2');
    config()->set('cms.contact.rspamd.threshold', 8);

    Http::fake([
        '127.0.0.1:11333/*' => Http::response(['score' => 7.5, 'action' => 'add header']),
    ]);

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))->assertStatus(303);

    Mail::assertQueuedCount(1);
});

test('when rspamd fails the message is sent and a warning is logged', function (Closure $answer): void {
    config()->set('cms.contact.rspamd.url', 'http://127.0.0.1:11333/checkv2');

    Http::fake([
        '127.0.0.1:11333/*' => $answer(),
    ]);

    Log::spy();

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))->assertStatus(303);

    Mail::assertQueuedCount(1);

    Log::shouldHaveReceived('warning')->once();
})->with([
    'no connection' => [fn () => Http::failedConnection()],
    'server error' => [fn () => Http::response('oops', 500)],
    'unexpected answer' => [fn () => Http::response(['error' => 'no message'])],
]);

test('without an rspamd url nothing is sent to rspamd', function (): void {
    Http::fake();

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))->assertStatus(303);

    Http::assertNothingSent();
    Mail::assertQueuedCount(1);
});

test('without both turnstile keys the form has no widget and needs no token', function (?string $siteKey, ?string $secretKey): void {
    useTurnstile($siteKey, $secretKey);

    Http::fake();

    $response = $this->get('/contact')
        ->assertOk()
        ->assertDontSee('cf-turnstile', false)
        ->assertDontSee(Turnstile::SCRIPT_URL, false);

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("script-src 'none'")
        ->not->toContain(Turnstile::HOST);

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))->assertStatus(303);

    Http::assertNothingSent();
    Mail::assertQueuedCount(1);
})->with([
    'no keys' => [null, null],
    'only a site key' => ['site-key', null],
    'only a secret key' => [null, 'secret-key'],
    'blank keys' => [' ', ' '],
]);

test('with turnstile keys the form renders one widget and the policy allows cloudflare', function (): void {
    useTurnstile('site-key', 'secret-key');

    addBlock($this->page, 'contact_form', [
        'title' => 'Second form',
    ]);

    $response = $this->get('/contact')
        ->assertOk()
        ->assertSee('data-sitekey="site-key"', false)
        ->assertDontSee('secret-key');

    expect(substr_count((string) $response->getContent(), 'class="cf-turnstile'))->toBe(2)
        ->and(substr_count((string) $response->getContent(), Turnstile::SCRIPT_URL))->toBe(1)
        ->and($response->headers->get('Content-Security-Policy'))
        ->toContain(sprintf('script-src %s;', Turnstile::HOST))
        ->toContain(sprintf('frame-src %s', Turnstile::HOST));

    expect($this->get('/privacy')->headers->get('Content-Security-Policy'))
        ->toContain("script-src 'none'")
        ->not->toContain(Turnstile::HOST);
});

test('with turnstile a submission without a token gets the sent redirect but no mail', function (): void {
    useTurnstile('site-key', 'secret-key');
    config()->set('cms.contact.rspamd.url', 'http://127.0.0.1:11333/checkv2');

    Http::fake();
    Log::spy();

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))
        ->assertStatus(303)
        ->assertRedirect(sprintf('/contact?verzonden=%1$d#contact-%1$d', $this->block->id));

    Http::assertNothingSent();
    Mail::assertNothingOutgoing();

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $text, array $context): bool => $context['reason'] === 'turnstile');
});

test('with turnstile a valid token is verified with cloudflare before rspamd and sent', function (): void {
    useTurnstile('site-key', 'secret-key');

    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => true]),
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
        ->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, [
            Turnstile::RESPONSE_FIELD => 'good-token',
        ]))
        ->assertStatus(303);

    Mail::assertQueuedCount(1);

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === Turnstile::VERIFY_URL
        && $request->isForm()
        && $request['secret'] === 'secret-key'
        && $request['response'] === 'good-token'
        && $request['remoteip'] === '10.0.0.9');
});

test('with turnstile an invalid token is rejected without asking rspamd and counts toward the hourly limit', function (): void {
    useTurnstile('site-key', 'secret-key');
    config()->set('cms.contact.rspamd.url', 'http://127.0.0.1:11333/checkv2');

    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']]),
    ]);

    Log::spy();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, [
            Turnstile::RESPONSE_FIELD => 'bad-token',
        ]))->assertStatus(303);
    }

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, [
        Turnstile::RESPONSE_FIELD => 'bad-token',
    ]))->assertStatus(429);

    Mail::assertNothingOutgoing();

    Http::assertSentCount(5);
    Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '11333'));

    Log::shouldHaveReceived('info')
        ->times(5)
        ->withArgs(fn (string $text, array $context): bool => $context['reason'] === 'turnstile');
    Log::shouldNotHaveReceived('warning');
});

test('a turnstile token longer than the maximum is rejected without asking cloudflare', function (): void {
    useTurnstile('site-key', 'secret-key');

    Http::fake();

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, [
        Turnstile::RESPONSE_FIELD => str_repeat('a', Turnstile::MAX_TOKEN_LENGTH + 1),
    ]))->assertStatus(303);

    Mail::assertNothingOutgoing();
    Http::assertNothingSent();
});

test('a refused turnstile secret key accepts the message and logs a warning', function (): void {
    useTurnstile('site-key', 'wrong-secret');

    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-secret']]),
    ]);

    Log::spy();

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, [
        Turnstile::RESPONSE_FIELD => 'token',
    ]))->assertStatus(303);

    Mail::assertQueued(ContactFormSubmitted::class);

    Log::shouldHaveReceived('warning')->once();
});

test('when cloudflare cannot be reached a token is accepted and a warning is logged', function (Closure $answer): void {
    useTurnstile('site-key', 'secret-key');

    Http::fake([
        'challenges.cloudflare.com/*' => $answer(),
    ]);

    Log::spy();

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, [
        Turnstile::RESPONSE_FIELD => 'token',
    ]))->assertStatus(303);

    Mail::assertQueuedCount(1);

    Log::shouldHaveReceived('warning')->once();
})->with([
    'no connection' => [fn () => Http::failedConnection()],
    'server error' => [fn () => Http::response('oops', 502)],
    'unexpected answer' => [fn () => Http::response(['error' => 'none'])],
]);

test('an app can serve the turnstile keys from its own binding', function (): void {
    app()->bind(TurnstileKeys::class, fn (): TurnstileKeys => new class implements TurnstileKeys
    {
        public function siteKey(): ?string
        {
            return 'settings-site-key';
        }

        public function secretKey(): ?string
        {
            return 'settings-secret-key';
        }
    });

    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => true]),
    ]);

    $this->get('/contact')->assertSee('data-sitekey="settings-site-key"', false);

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block, [
        Turnstile::RESPONSE_FIELD => 'token',
    ]))->assertStatus(303);

    Http::assertSent(fn (HttpRequest $request): bool => $request['secret'] === 'settings-secret-key');
    Mail::assertQueuedCount(1);
});

test('the turnstile secret key is stored encrypted, hidden, kept when left empty and cleared on request', function (): void {
    useTurnstile('site-key', 'secret-key');

    $stored = DB::table('cms_settings')->value('contact_turnstile_secret_key');

    expect($stored)->not->toContain('secret-key')
        ->and(Crypt::decryptString((string) $stored))->toBe('secret-key')
        ->and(SiteSettings::current()->toArray())->not->toHaveKey('contact_turnstile_secret_key')
        ->and(SiteSettings::current()->hasTurnstileSecret())->toBeTrue();

    app(SaveSiteSettings::class)->handle([
        'contact_turnstile_site_key' => 'new-site-key',
        'contact_turnstile_secret_key' => null,
    ]);

    expect(SiteSettings::current())
        ->contact_turnstile_site_key->toBe('new-site-key')
        ->contact_turnstile_secret_key->toBe('secret-key');

    app(SaveSiteSettings::class)->handle([
        'contact_turnstile_secret_key' => null,
        SiteSettings::REMOVE_TURNSTILE_SECRET => true,
    ]);

    expect(SiteSettings::current()->hasTurnstileSecret())->toBeFalse();

    $this->get('/contact')->assertDontSee('cf-turnstile', false);
});

test('a turnstile secret key that cannot be decrypted switches turnstile off', function (): void {
    useTurnstile('site-key', 'secret-key');

    DB::table('cms_settings')->update([
        'contact_turnstile_secret_key' => 'not-encrypted',
    ]);

    Http::fake();

    $this->get('/contact')
        ->assertOk()
        ->assertDontSee('cf-turnstile', false);

    $this->post(route('cms.contact', ['block' => $this->block->id]), contactSubmission($this->block))->assertStatus(303);

    Http::assertNothingSent();
    Mail::assertQueuedCount(1);
});
