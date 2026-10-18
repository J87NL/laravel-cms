<?php

declare(strict_types=1);

namespace J87NL\Cms\Blocks\Types;

use Illuminate\Support\Carbon;
use J87NL\Cms\Blocks\AbstractBlockType;
use J87NL\Cms\Blocks\BlockContext;
use TranquilTools\FormBuilder\Fields\Date;
use TranquilTools\FormBuilder\Fields\Repeater;
use TranquilTools\FormBuilder\Fields\Text;
use TranquilTools\FormBuilder\Fields\Textarea;

class DatesBlock extends AbstractBlockType
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
            'items' => $this->items($data),
        ];
    }

    public function viewData(array $data, BlockContext $context): array
    {
        return [
            'title' => $this->string($data, 'title'),
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
