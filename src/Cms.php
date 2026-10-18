<?php

declare(strict_types=1);

namespace J87NL\Cms;

use J87NL\Cms\Blocks\BlockRegistry;

class Cms
{
    public function __construct(
        private readonly BlockRegistry $blocks,
    ) {}

    public function blocks(): BlockRegistry
    {
        return $this->blocks;
    }
}
