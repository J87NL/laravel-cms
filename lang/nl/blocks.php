<?php

declare(strict_types=1);

return [
    'rich_text' => [
        'label' => 'Tekst',
        'fields' => [
            'html' => 'Tekst',
        ],
    ],
    'text_image' => [
        'label' => 'Tekst met afbeelding',
        'fields' => [
            'html' => 'Tekst',
            'media_id' => 'Afbeelding',
            'image_position' => 'Positie van de afbeelding',
        ],
        'positions' => [
            'left' => 'Links',
            'right' => 'Rechts',
        ],
    ],
    'image' => [
        'label' => 'Afbeelding',
        'fields' => [
            'media_id' => 'Afbeelding',
        ],
    ],
    'gallery' => [
        'label' => 'Galerij',
        'fields' => [
            'media_ids' => 'Afbeeldingen',
        ],
    ],
    'call_to_action' => [
        'label' => 'Oproep tot actie',
        'fields' => [
            'title' => 'Titel',
            'text' => 'Tekst',
            'button_label' => 'Tekst op de knop',
            'url' => 'Link',
        ],
        'help' => [
            'url' => 'Een pad op deze site zoals /contact, of een volledig adres dat begint met https://, mailto: of tel:.',
        ],
    ],
    'embed' => [
        'label' => 'Insluiting',
        'fields' => [
            'url' => 'Adres van de insluiting',
            'title' => 'Omschrijving',
        ],
        'help' => [
            'url' => 'Alleen privacyvriendelijke insluitingen: een YouTube-adres op youtube-nocookie.com/embed/ of een insluitadres van OpenStreetMap.',
            'title' => 'Wordt voorgelezen door schermlezers, bijvoorbeeld "Route naar de markt".',
        ],
    ],
    'dates' => [
        'label' => 'Datums',
        'add_item' => 'Datum toevoegen',
        'item' => 'Datum',
        'fields' => [
            'title' => 'Kop',
            'items' => 'Datums',
            'date' => 'Datum',
            'item_title' => 'Titel',
            'description' => 'Omschrijving',
            'location_name' => 'Locatie',
            'location_address' => 'Adres van de locatie',
        ],
        'help' => [
            'location_name' => 'Optioneel. Met een locatie kunnen zoekmachines de datums als evenement tonen.',
        ],
    ],
];
