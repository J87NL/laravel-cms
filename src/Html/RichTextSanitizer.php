<?php

declare(strict_types=1);

namespace J87NL\Cms\Html;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class RichTextSanitizer
{
    private const int MAX_INPUT_LENGTH = 500_000;

    /**
     * @var array<string, list<string>>
     */
    private const array ALLOWED_ELEMENTS = [
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'p' => [],
        'br' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'strong' => [],
        'em' => [],
        'blockquote' => [],
        'table' => [],
        'thead' => [],
        'tbody' => [],
        'tr' => [],
        'th' => [
            'colspan',
            'rowspan',
            'scope',
        ],
        'td' => [
            'colspan',
            'rowspan',
        ],
        'a' => [
            'href',
            'title',
            'target',
        ],
    ];

    /**
     * @var list<string>
     */
    private const array BLOCKED_ELEMENTS = [
        'address',
        'article',
        'b',
        'caption',
        'center',
        'cite',
        'code',
        'dd',
        'div',
        'dl',
        'dt',
        'figcaption',
        'figure',
        'font',
        'h1',
        'h5',
        'h6',
        'i',
        'mark',
        'pre',
        's',
        'section',
        'small',
        'span',
        'sub',
        'sup',
        'tfoot',
        'u',
    ];

    private ?HtmlSanitizer $sanitizer = null;

    public function sanitize(?string $html): string
    {
        if (is_null($html) || trim($html) === '') {
            return '';
        }

        return trim($this->sanitizer()->sanitize($html));
    }

    private function sanitizer(): HtmlSanitizer
    {
        return $this->sanitizer ??= new HtmlSanitizer($this->config());
    }

    private function config(): HtmlSanitizerConfig
    {
        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes([
                'http',
                'https',
                'mailto',
                'tel',
            ])
            ->allowRelativeLinks()
            ->forceAttribute('a', 'rel', 'noopener')
            ->withAttributeSanitizer(new LinkTargetSanitizer)
            ->withAttributeSanitizer(new LinkHrefSanitizer)
            ->withMaxInputLength(self::MAX_INPUT_LENGTH);

        foreach (self::ALLOWED_ELEMENTS as $element => $attributes) {
            $config = $config->allowElement($element, $attributes);
        }

        foreach (self::BLOCKED_ELEMENTS as $element) {
            $config = $config->blockElement($element);
        }

        return $config;
    }
}
