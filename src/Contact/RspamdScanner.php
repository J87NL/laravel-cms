<?php

declare(strict_types=1);

namespace J87NL\Cms\Contact;

use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use J87NL\Cms\Mail\ContactFormSubmitted;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface;

class RspamdScanner
{
    public function rejects(ContactFormSubmitted $mail, string $recipient): bool
    {
        $url = trim((string) config('cms.contact.rspamd.url'));

        if ($url === '') {
            return false;
        }

        $timeout = (float) config('cms.contact.rspamd.timeout', 2);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->withBody($this->compose($mail, $recipient), 'message/rfc822')
                ->post($url);
        } catch (ExceptionInterface|HttpClientException $e) {
            $this->warn(['error' => $e::class]);

            return false;
        }

        $score = $response->json('score');

        if ($response->failed() || ! is_numeric($score)) {
            $this->warn(['status' => $response->status()]);

            return false;
        }

        return $response->json('action') === 'reject' || (float) $score >= (float) config('cms.contact.rspamd.threshold', 6);
    }

    private function compose(ContactFormSubmitted $mail, string $recipient): string
    {
        return (new Email)
            ->from(new Address($mail->senderEmail, Str::squish($mail->senderName)))
            ->to($recipient)
            ->subject((string) $mail->envelope()->subject)
            ->text(view('cms::mail.contact-form', $mail->buildViewData())->render())
            ->toString();
    }

    /**
     * @param  array<string, int|string>  $context
     */
    private function warn(array $context): void
    {
        Log::warning('The contact form could not be checked by rspamd, so the message was sent unchecked.', $context);
    }
}
