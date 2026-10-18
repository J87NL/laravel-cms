<?php

declare(strict_types=1);

namespace J87NL\Cms\Redirects;

final class ImportResult
{
    public int $imported = 0;

    /**
     * @var array<int, string>
     */
    public array $errors = [];

    public function fail(int $line, string $message): void
    {
        $this->errors[$line] = $message;
    }
}
