<?php

declare(strict_types=1);

namespace J87NL\Cms\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use J87NL\Cms\Findability\LlmsTxt;
use J87NL\Cms\Findability\RobotsTxt;
use J87NL\Cms\Sitemap\SitemapGenerator;

class FindabilityController extends Controller
{
    public function sitemap(SitemapGenerator $generator): Response
    {
        return response($generator->build(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    public function robots(RobotsTxt $robots): Response
    {
        return response($robots->build(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function llms(LlmsTxt $llms): Response
    {
        return response($llms->build(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
