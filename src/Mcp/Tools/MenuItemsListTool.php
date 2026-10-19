<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Mcp\CmsPresenter;
use J87NL\Cms\Mcp\Concerns\InteractsWithCms;
use J87NL\Cms\Models\Menu;
use J87NL\Cms\Models\MenuItem;
use J87NL\Cms\Pages\PageVisibility;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('menu_items_list')]
#[Title('List CMS menu items')]
#[Description('Lists the items of the website menus in display order, per menu key (for example main and footer). Each item has id, label, page_id or url, href, parent_id and is_visible: an item that links to a page only shows while that page and its parents are published. Pass menu to get one menu. Change items with menu_item_save, which takes effect on the live website immediately.')]
#[IsReadOnly]
#[IsDestructive(false)]
class MenuItemsListTool extends Tool
{
    use InteractsWithCms;

    public function __construct(
        private readonly CmsPresenter $presenter,
        private readonly PageVisibility $visibility,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->authorizeRead($request, CmsPermission::Menus);

        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'menu' => ['nullable', 'string', 'max:64'],
        ]);

        $keys = $this->menuKeys();

        if (! is_null($data['menu'] ?? null)) {
            if (! in_array($data['menu'], $keys, true)) {
                return Response::error(sprintf('The menu "%s" does not exist. Use one of: %s.', $data['menu'], implode(', ', $keys)));
            }

            $keys = [(string) $data['menu']];
        }

        return Response::structured([
            'menus' => array_map(fn (string $key): array => $this->menu($key), $keys),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function menu(string $key): array
    {
        $menu = $this->findMenu($key);

        if (is_null($menu)) {
            return [
                'key' => $key,
                'label' => (new Menu(['key' => $key]))->label(),
                'items' => [],
            ];
        }

        $items = MenuItem::query()
            ->with('page')
            ->where('menu_id', $menu->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $pagePaths = array_values($items
            ->map(fn (MenuItem $item): ?string => $item->page?->path)
            ->filter()
            ->all());
        $hiddenAncestors = $this->visibility->hiddenAncestorPaths($pagePaths);

        return [
            'key' => $menu->key,
            'label' => $menu->label(),
            'items' => array_values($items
                ->map(fn (MenuItem $item): array => $this->presenter->menuItem(
                    $item,
                    $item->isPublic() && (is_null($item->page) || ! $this->visibility->isHiddenBy($item->page->path, $hiddenAncestors)),
                ))
                ->all()),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'menu' => $schema->string()->description(sprintf('The menu key, one of: %s. Leave out to list every menu.', implode(', ', $this->menuKeys()))),
        ];
    }
}
