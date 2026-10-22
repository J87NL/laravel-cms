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

    /**
     * @return list<string>
     */
    public static function links(string $html): array
    {
        preg_match_all('#<[a-z][a-z0-9]*\b[^>]*?\s(?:href|src)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $html, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        return array_values(array_map(
            fn (array $match): string => trim(html_entity_decode((string) ($match[1] ?? $match[2] ?? $match[3]), ENT_QUOTES | ENT_HTML5)),
            $matches,
        ));
    }

    public static function looksLikeHtml(string $value): bool
    {
        return preg_match('#</?[a-z][a-z0-9]*\b[^>]*>#i', $value) === 1;
    }
}
