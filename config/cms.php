<?php

declare(strict_types=1);

return [

    'permissions_config_key' => 'j87nl.permissions',

    'layout' => 'cms::layouts.default',

    'fallback_home_view' => null,

    'routes' => [
        'enabled' => true,
        'home_name' => 'cms.home',
        'middleware' => [],
    ],

    'blocks' => [
        'disabled' => [],
    ],

    'menus' => [
        'main',
        'footer',
    ],

    'revisions' => [
        'keep' => 20,
    ],

    'user_model' => null,

    'media' => [
        'disk' => 'public',
        'max_upload_kilobytes' => 10240,
        'max_pixels' => 40_000_000,
        'widths' => [
            480,
            960,
            1600,
        ],
    ],

    'embeds' => [
        'youtube' => [
            'hosts' => [
                'www.youtube-nocookie.com',
            ],
            'path_pattern' => '#^/embed/[A-Za-z0-9_-]{11}$#',
        ],
        'openstreetmap' => [
            'hosts' => [
                'www.openstreetmap.org',
            ],
            'path_pattern' => '#^/export/embed\.html$#',
        ],
    ],

    'rich_text' => [
        'editor' => 'hugerte',
        'languages' => [
            'nl',
        ],
        'editor_options' => [
            'menubar' => false,
            'statusbar' => false,
            'plugins' => 'autolink link lists table',
            'toolbar' => 'undo redo | blocks | bold italic | link blockquote | bullist numlist | table',
            'block_formats' => 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4',
            'valid_elements' => 'p,br,h2,h3,h4,strong/b,em/i,blockquote,ul,ol,li,a[href|target|title],table,thead,tbody,tr,th[colspan|rowspan|scope],td[colspan|rowspan]',
            'invalid_elements' => 'script,iframe,style,img,object,embed',
            'link_default_protocol' => 'https',
            'link_title' => false,
            'table_toolbar' => 'tableprops tabledelete | tableinsertrowbefore tableinsertrowafter tabledeleterow | tableinsertcolbefore tableinsertcolafter tabledeletecol',
            'table_appearance_options' => false,
            'table_advtab' => false,
            'table_cell_advtab' => false,
            'table_row_advtab' => false,
            'table_style_by_css' => false,
            'paste_data_images' => false,
            'convert_urls' => false,
        ],
    ],

];
