<?php

declare(strict_types=1);

namespace J87NL\Cms\Contact;

use J87NL\Cms\Mail\ContactFormSubmitted;

class ContactFormSpamFilter
{
    private const string LINK_START = '(?<![\p{L}\p{N}/.@:_-])(?:https?://|www\.)';

    public function __construct(
        private readonly RspamdScanner $rspamd,
    ) {}

    public function rejection(ContactFormSubmitted $mail, string $recipient): ?ContactFormRejection
    {
        return $this->contentRejection($mail) ?? ($this->rspamd->rejects($mail, $recipient) ? ContactFormRejection::Rspamd : null);
    }

    private function contentRejection(ContactFormSubmitted $mail): ?ContactFormRejection
    {
        if (! mb_check_encoding($mail->body, 'UTF-8')) {
            return ContactFormRejection::InvalidEncoding;
        }

        $links = (int) preg_match_all(sprintf('~%s~iu', self::LINK_START), $mail->body);

        if ($links > 0 && (bool) config('cms.contact.reject_only_links', true) && ! $this->hasTextBesideLinks($mail->body)) {
            return ContactFormRejection::OnlyLinks;
        }

        $maximum = config('cms.contact.max_links', 2);

        if (is_numeric($maximum) && $links > (int) $maximum) {
            return ContactFormRejection::TooManyLinks;
        }

        if ($this->containsBlockedKeyword($mail)) {
            return ContactFormRejection::BlockedKeyword;
        }

        return null;
    }

    private function hasTextBesideLinks(string $body): bool
    {
        return preg_match('~[\p{L}\p{N}]~u', (string) preg_replace(sprintf('~%s[^\s<>"\']*~iu', self::LINK_START), '', $body)) === 1;
    }

    private function containsBlockedKeyword(ContactFormSubmitted $mail): bool
    {
        $text = implode("\n", [
            $mail->senderName,
            $mail->senderEmail,
            $mail->body,
        ]);

        foreach ((array) config('cms.contact.blocked_keywords', []) as $keyword) {
            $keyword = trim((string) $keyword);

            if ($keyword !== '' && mb_stripos($text, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }
}
