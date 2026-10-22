<?php

declare(strict_types=1);

namespace J87NL\Cms\Html;

use Dom\HTMLDocument;

class PlainText
{
    /**
     * @var list<string>
     */
    private const array LINK_ATTRIBUTES = [
        'href',
        'src',
        'srcset',
    ];

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
        $document = HTMLDocument::createFromString(sprintf('<!DOCTYPE html><html><body>%s</body></html>', $html), LIBXML_NOERROR);
        $links = [];

        foreach ($document->getElementsByTagName('*') as $element) {
            foreach (self::LINK_ATTRIBUTES as $attribute) {
                if (! $element->hasAttribute($attribute)) {
                    continue;
                }

                $links[] = trim((string) $element->getAttribute($attribute));
            }
        }

        return $links;
    }

    public static function looksLikeHtml(string $value): bool
    {
        return preg_match('#</?[a-z][a-z0-9]*\b[^>]*>#i', $value) === 1;
    }
}
