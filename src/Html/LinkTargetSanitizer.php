<?php

declare(strict_types=1);

namespace J87NL\Cms\Html;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

class LinkTargetSanitizer implements AttributeSanitizerInterface
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
            'target',
        ];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return $value === '_blank' ? $value : null;
    }
}
