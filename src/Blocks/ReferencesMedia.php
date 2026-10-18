<?php

declare(strict_types=1);

namespace J87NL\Cms\Blocks;

interface ReferencesMedia
{
    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    public function mediaIds(array $data): array;
}
