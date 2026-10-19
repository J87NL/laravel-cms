<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use J87NL\Cms\Actions\SaveMenuItem;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Mcp\CmsPresenter;
use J87NL\Cms\Mcp\Concerns\InteractsWithCms;
use J87NL\Cms\Models\MenuItem;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Pages\PageVisibility;
use J87NL\Cms\Rules\SafeLink;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('menu_item_save')]
#[Title('Save CMS menu item')]
#[Description('Creates a menu item, or updates one when item_id is given. Menus are live: the change takes effect on the website immediately, so confirm it with the user first. Link the item to a page with page_id, or to an address with url and label. A page item may point at any page, but it only shows on the website while that page and its parents are published. Menus have two levels: parent_id must be a top-level item of the same menu. New items go at the end of the menu. On an update, fields you leave out keep their current value; pass parent_id null to move an item to the top level.')]
#[IsReadOnly(false)]
#[IsDestructive]
class MenuItemSaveTool extends Tool
{
    use InteractsWithCms;

    public function __construct(
        private readonly CmsPresenter $presenter,
        private readonly PageVisibility $visibility,
    ) {}

    public function handle(Request $request, SaveMenuItem $saveMenuItem): Response|ResponseFactory
    {
        $user = $this->authorizeWrite($request, CmsPermission::Menus);

        if ($user instanceof Response) {
            return $user;
        }

        $input = $request->validate([
            'menu' => ['required', 'string', 'max:64'],
            'item_id' => ['nullable', 'integer'],
            'page_id' => ['nullable', 'required_without_all:item_id,url', 'prohibits:url', 'integer', Rule::exists((new Page)->getTable(), 'id')],
            'url' => ['nullable', 'required_without_all:item_id,page_id', 'string', 'max:2048', new SafeLink],
            'label' => ['nullable', 'string', 'max:80'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $menu = $this->resolveMenu((string) $input['menu']);

        if ($menu instanceof Response) {
            return $menu;
        }

        $item = new MenuItem;

        if (! is_null($input['item_id'] ?? null)) {
            $item = MenuItem::query()
                ->where('menu_id', $menu->id)
                ->find((int) $input['item_id']);

            if (is_null($item)) {
                return Response::error(sprintf('Menu item %d is not part of the menu "%s". Use menu_items_list to see the item ids.', (int) $input['item_id'], $menu->key));
            }
        }

        $created = ! $item->exists;
        $pageId = $item->page_id;
        $url = $item->url;

        if (! is_null($input['page_id'] ?? null)) {
            $pageId = (int) $input['page_id'];
            $url = null;
        }

        if (! is_null($input['url'] ?? null)) {
            $pageId = null;
            $url = (string) $input['url'];
        }

        $label = array_key_exists('label', $input) ? $input['label'] : $item->label;
        $parentId = array_key_exists('parent_id', $input) ? $input['parent_id'] : $item->parent_id;

        $saveMenuItem->handle($menu, $item, $pageId, $url, is_null($label) ? null : (string) $label, is_null($parentId) ? null : (int) $parentId);

        $item->refresh()->load('page');
        $isVisible = $item->isPublic() && (is_null($item->page) || $this->visibility->isPublic($item->page));

        $message = sprintf('Menu item "%s" %s in the menu "%s".', $item->displayLabel(), $created ? 'added' : 'saved', $menu->key);

        if (! $isVisible) {
            $message = sprintf('%s It stays hidden on the website until the linked page is published.', $message);
        }

        return Response::structured([
            'message' => $message,
            'item' => $this->presenter->menuItem($item, $isVisible),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'menu' => $schema->string()->description(sprintf('The menu key, one of: %s.', implode(', ', $this->menuKeys())))->required(),
            'item_id' => $schema->integer()->description('The id of the item to update, from menu_items_list. Leave out to create a new item.'),
            'page_id' => $schema->integer()->description('The id of the page to link to, from pages_list. Use either page_id or url.'),
            'url' => $schema->string()->description('An address such as /contact, https://..., mailto: or tel:. Use either page_id or url.'),
            'label' => $schema->string()->description('The text in the menu. Required with url; for a page item it defaults to the menu title of the page.'),
            'parent_id' => $schema->integer()->nullable()->description('The id of a top-level item of the same menu to place this item under, or null for the top level.'),
        ];
    }
}
