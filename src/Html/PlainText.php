<?php

declare(strict_types=1);

namespace J87NL\Cms\Html;

class PlainText
{
    public static function fromHtml(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|iframe)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function looksLikeHtml(string $value): bool
    {
        return preg_match('#</?[a-z][a-z0-9]*\b[^>]*>#i', $value) === 1;
    }
}
