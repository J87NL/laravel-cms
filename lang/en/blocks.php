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
    'contact_form' => [
        'label' => 'Contact form',
        'fields' => [
            'title' => 'Heading',
            'intro' => 'Introduction',
            'button_label' => 'Button text',
            'success_message' => 'Thank-you text',
        ],
        'help' => [
            'button_label' => 'Empty means "Send".',
            'success_message' => 'Shown after sending. The form mails to the address in the site settings.',
        ],
        'name' => 'Name',
        'email' => 'Email address',
        'message' => 'Message',
        'trap' => 'Leave this field empty',
        'default_button' => 'Send',
        'default_success' => 'Thank you for your message. You will get a reply as soon as possible.',
        'has_errors' => 'The message has not been sent yet:',
        'privacy_before' => 'Read how your details are handled in the',
        'privacy_link' => 'privacy statement',
        'expired' => 'The form has expired. Please try again.',
        'unavailable' => 'The form cannot send messages right now. Please try again later.',
        'mail_subject' => 'Message via :site: :page',
        'mail_intro' => 'Message from the contact form on :url',
    ],
];
