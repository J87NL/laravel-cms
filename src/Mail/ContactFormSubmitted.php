<?php

declare(strict_types=1);

namespace J87NL\Cms\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

class ContactFormSubmitted extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $senderName,
        public readonly string $senderEmail,
        public readonly string $body,
        public readonly string $siteName,
        public readonly string $pageTitle,
        public readonly string $pageUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [
                new Address($this->singleLine($this->senderEmail), $this->singleLine($this->senderName)),
            ],
            subject: __('cms::blocks.contact_form.mail_subject', [
                'site' => $this->singleLine($this->siteName),
                'page' => $this->singleLine($this->pageTitle),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'cms::mail.contact-form',
        );
    }

    private function singleLine(string $value): string
    {
        return Str::squish($value);
    }
}
