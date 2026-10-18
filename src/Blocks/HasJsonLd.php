<?php

declare(strict_types=1);

namespace J87NL\Cms\Blocks;

interface HasJsonLd
{
    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public function jsonLd(array $data): array;
}
