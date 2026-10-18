<?php

declare(strict_types=1);

return [
    'rich_text' => [
        'label' => 'Text',
        'fields' => [
            'html' => 'Text',
        ],
    ],
    'text_image' => [
        'label' => 'Text with image',
        'fields' => [
            'html' => 'Text',
            'media_id' => 'Image',
            'image_position' => 'Image position',
        ],
        'positions' => [
            'left' => 'Left',
            'right' => 'Right',
        ],
    ],
    'image' => [
        'label' => 'Image',
        'fields' => [
            'media_id' => 'Image',
        ],
    ],
    'gallery' => [
        'label' => 'Gallery',
        'fields' => [
            'media_ids' => 'Images',
        ],
    ],
    'call_to_action' => [
        'label' => 'Call to action',
        'fields' => [
            'title' => 'Title',
            'text' => 'Text',
            'button_label' => 'Button text',
            'url' => 'Link',
        ],
        'help' => [
            'url' => 'A path on this site such as /contact, or a full address starting with https://, mailto: or tel:.',
        ],
    ],
    'embed' => [
        'label' => 'Embed',
        'fields' => [
            'url' => 'Embed address',
            'title' => 'Description',
        ],
        'help' => [
            'url' => 'Only privacy-friendly embeds: a YouTube address on youtube-nocookie.com/embed/ or an OpenStreetMap embed address.',
            'title' => 'Read out by screen readers, for example "Route to the market".',
        ],
    ],
    'dates' => [
        'label' => 'Dates',
        'add_item' => 'Add date',
        'item' => 'Date',
        'fields' => [
            'title' => 'Heading',
            'items' => 'Dates',
            'date' => 'Date',
            'item_title' => 'Title',
            'description' => 'Description',
            'location_name' => 'Location',
            'location_address' => 'Location address',
        ],
        'help' => [
            'location_name' => 'Optional. With a location, search engines can show the dates as events.',
        ],
    ],
];
