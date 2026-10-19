<?php

declare(strict_types=1);

namespace J87NL\Cms\Blocks\Types;

use Illuminate\Support\Carbon;
use J87NL\Cms\Blocks\AbstractBlockType;
use J87NL\Cms\Blocks\BlockContext;
use J87NL\Cms\Blocks\HasJsonLd;
use TranquilTools\FormBuilder\Fields\Date;
use TranquilTools\FormBuilder\Fields\Repeater;
use TranquilTools\FormBuilder\Fields\Text;
use TranquilTools\FormBuilder\Fields\Textarea;

class DatesBlock extends AbstractBlockType implements HasJsonLd
{
    public function key(): string
    {
        return 'dates';
    }

    public function view(): string
    {
        return 'cms::blocks.dates';
    }

    public function fields(): array
    {
        return [
            Text::make('title')
                ->label(__('cms::blocks.dates.fields.title'))
                ->rules([
                    'nullable',
                    'string',
                    'max:160',
                ]),

            Text::make('location_name')
                ->label(__('cms::blocks.dates.fields.location_name'))
                ->help(__('cms::blocks.dates.help.location_name'))
                ->rules([
                    'nullable',
                    'string',
                    'max:160',
                ]),

            Text::make('location_address')
                ->label(__('cms::blocks.dates.fields.location_address'))
                ->rules([
                    'nullable',
                    'string',
                    'max:255',
                ]),

            Repeater::make('items')
                ->label(__('cms::blocks.dates.fields.items'))
                ->addActionLabel(__('cms::blocks.dates.add_item'))
                ->itemLabel(__('cms::blocks.dates.item'))
                ->reorderable()
                ->min(1)
                ->required()
                ->rules([
                    'min:1',
                    'max:100',
                ])
                ->fields([
                    Date::make('date')
                        ->label(__('cms::blocks.dates.fields.date'))
                        ->required()
                        ->rules([
                            'date',
                        ]),

                    Text::make('title')
                        ->label(__('cms::blocks.dates.fields.item_title'))
                        ->required()
                        ->rules([
                            'string',
                            'max:160',
                        ]),

                    Textarea::make('description')
                        ->label(__('cms::blocks.dates.fields.description'))
                        ->rules([
                            'nullable',
                            'string',
                            'max:1000',
                        ]),
                ]),
        ];
    }

    public function prepare(array $data): array
    {
        return [
            'title' => trim($this->string($data, 'title')),
            'location_name' => trim($this->string($data, 'location_name')),
            'location_address' => trim($this->string($data, 'location_address')),
            'items' => $this->items($data),
        ];
    }

    public function viewData(array $data, BlockContext $context): array
    {
        return [
            'title' => $this->string($data, 'title'),
            'location' => trim(implode(', ', array_filter([
                trim($this->string($data, 'location_name')),
                trim($this->string($data, 'location_address')),
            ]))),
            'items' => array_map(
                fn (array $item): array => [
                    ...$item,
                    'carbon' => Carbon::parse($item['date']),
                ],
                $this->items($data),
            ),
        ];
    }

    public function toPlainText(array $data): string
    {
        $lines = [$this->string($data, 'title')];

        foreach ($this->items($data) as $item) {
            $lines[] = trim(sprintf('%s %s %s', $item['date'], $item['title'], $item['description']));
        }

        return trim(implode(' ', $lines));
    }

    public function jsonLd(array $data): array
    {
        $location = $this->location($data);

        if (is_null($location)) {
            return [];
        }

        $events = [];

        foreach ($this->items($data) as $item) {
            $event = [
                '@type' => 'Event',
                'name' => $item['title'],
                'startDate' => $item['date'],
                'endDate' => $item['date'],
                'eventStatus' => 'https://schema.org/EventScheduled',
                'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
                'location' => $location,
            ];

            if ($item['description'] !== '') {
                $event['description'] = $item['description'];
            }

            $events[] = $event;
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function location(array $data): ?array
    {
        $name = trim($this->string($data, 'location_name'));
        $address = trim($this->string($data, 'location_address'));

        if ($name === '' && $address === '') {
            return null;
        }

        return [
            '@type' => 'Place',
            'name' => $name === '' ? $address : $name,
            'address' => $address === '' ? $name : $address,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{date: string, title: string, description: string}>
     */
    private function items(array $data): array
    {
        $items = $data['items'] ?? [];

        if (! is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $date = $this->string($item, 'date');

            if ($date === '' || strtotime($date) === false) {
                continue;
            }

            $normalized[] = [
                'date' => Carbon::parse($date)->toDateString(),
                'title' => trim($this->string($item, 'title')),
                'description' => trim($this->string($item, 'description')),
            ];
        }

        usort($normalized, fn (array $first, array $second): int => $first['date'] <=> $second['date']);

        return $normalized;
    }
}
