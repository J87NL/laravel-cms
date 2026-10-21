<?php

declare(strict_types=1);

namespace J87NL\Cms\Tests\Fixtures\Mcp;

use J87NL\Cms\Facades\Cms;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('CMS test server')]
#[Version('1.0.0')]
class CmsTestServer extends Server
{
    public int $defaultPaginationLength = 50;

    protected function boot(): void
    {
        $this->tools = [
            ...$this->tools,
            ...Cms::mcpTools(),
        ];
    }
}
