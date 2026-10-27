<?php

declare(strict_types=1);

namespace J87NL\Cms\Contact;

interface TurnstileKeys
{
    public function siteKey(): ?string;

    public function secretKey(): ?string;
}
