<?php

declare(strict_types=1);

namespace J87NL\Cms\Html;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

class LinkHrefSanitizer implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return [
            'a',
        ];
    }

    public function getSupportedAttributes(): ?array
    {
        return [
            'href',
        ];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $trimmed = trim($value);

        if (str_starts_with($trimmed, '//') || str_contains($trimmed, '\\') || preg_match('#^https?:(?!//)#i', $trimmed) === 1) {
            return null;
        }

        return $value;
    }
}
