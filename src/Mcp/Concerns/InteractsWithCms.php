<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Concerns;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Blocks\BlockType;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Mcp\BlockTypeSummary;
use J87NL\Cms\Mcp\CmsTools;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\Menu;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Validation\PageRules;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

trait InteractsWithCms
{
    protected function authorizeRead(Request $request, CmsPermission $permission): Authenticatable|Response
    {
        $user = $request->user();

        if (! $user instanceof Authenticatable) {
            return Response::error('No acting user is known for this request, so nothing was done. Ask a J87 hub admin to link your account to this app.');
        }

        if (! Gate::forUser($user)->allows($permission->value)) {
            return Response::error(sprintf('Your account lacks the CMS permission "%s", so %s was not run. Ask an administrator of this site for that permission.', $permission->value, $this->name()));
        }

        return $user;
    }

    protected function authorizeWrite(Request $request, CmsPermission $permission): Authenticatable|Response
    {
        $user = $request->user();

        if (! $user instanceof Authenticatable) {
            return $this->authorizeRead($request, $permission);
        }

        if (! $this->grantAllowsWrites()) {
            return Response::error(sprintf('Your J87 hub grant for this app is read-only, so %s was not run. Ask a J87 hub admin for a full grant to make changes.', $this->name()));
        }

        return $this->authorizeRead($request, $permission);
    }

    protected function grantAllowsWrites(): bool
    {
        return request()->header(CmsTools::GRANT_LEVEL_HEADER) === CmsTools::FULL_GRANT;
    }

    protected function userId(Authenticatable $user): ?int
    {
        $id = $user->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @param  Closure(Page): (Response|ResponseFactory)  $callback
     */
    protected function onWritablePage(Authenticatable $user, int $pageId, Closure $callback): Response|ResponseFactory
    {
        return DB::transaction(function () use ($user, $pageId, $callback): Response|ResponseFactory {
            $page = Page::query()
                ->lockForUpdate()
                ->find($pageId);

            if (is_null($page)) {
                return $this->pageNotFound($pageId);
            }

            $refusal = $this->pageWriteRefusal($user, $page);

            if (! is_null($refusal)) {
                return $refusal;
            }

            return $callback($page);
        });
    }

    protected function pageWriteRefusal(Authenticatable $user, Page $page): ?Response
    {
        if ($page->isPublished()) {
            return Response::error(sprintf('Page %d "%s" is published, so these tools cannot change it. Make this change in the CMS admin, or use page_draft_create for a new draft page.', $page->id, $page->title));
        }

        if (! is_null($page->publish_at)) {
            return Response::error(sprintf('Page %d "%s" is scheduled to be published on %s, so these tools cannot change it. Change the page or its schedule in the CMS admin.', $page->id, $page->title, $page->publish_at->toIso8601String()));
        }

        if (Gate::has(CmsTools::PAGE_WRITE_ABILITY) && ! Gate::forUser($user)->allows(CmsTools::PAGE_WRITE_ABILITY, $page)) {
            return Response::error(sprintf('You may not change page %d "%s". Ask an administrator of this site for access, or make the change in the CMS admin.', $page->id, $page->title));
        }

        return null;
    }

    protected function pageNotFound(int $pageId): Response
    {
        return Response::error(sprintf('Page %d was not found. Use pages_list to see the page ids.', $pageId));
    }

    /**
     * @return list<string>
     */
    protected function menuKeys(): array
    {
        $keys = config('cms.menus', []);

        return is_array($keys) ? array_values(array_map('strval', $keys)) : [];
    }

    protected function resolveMenu(string $key): Menu|Response
    {
        if (! in_array($key, $this->menuKeys(), true)) {
            return Response::error(sprintf('The menu "%s" does not exist. Use one of: %s.', $key, implode(', ', $this->menuKeys())));
        }

        return Menu::forKey($key);
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function pageRules(bool $creating): array
    {
        $rules = [];

        foreach (PageRules::fields() as $field => $fieldRules) {
            $presence = match (true) {
                $field === 'title' && $creating => 'required',
                $creating => 'nullable',
                default => 'sometimes',
            };

            $rules[$field] = [$presence, ...$fieldRules];
        }

        return $rules;
    }

    protected function likePattern(string $search): string
    {
        return sprintf('%%%s%%', str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search));
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function likeClause(Builder $query, string $column): string
    {
        return sprintf("%s like ? escape '!'", $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($column)));
    }

    protected function findMenu(string $key): ?Menu
    {
        return Menu::query()
            ->where('key', $key)
            ->first();
    }

    /**
     * @return array<string, Type>
     */
    protected function pageSchema(JsonSchema $schema): array
    {
        $schemaTypes = PageRules::schemaTypes();
        $schemaType = $schema->string()->description('The schema.org type of the page for structured data; WebPage when left empty.');

        return [
            'slug' => $schema->string()->description('The last part of the address, for example "over-ons". Derived from the title when left empty.'),
            'parent_id' => $schema->integer()->nullable()->description('The id of the parent page, or null for a top-level page.'),
            'menu_title' => $schema->string()->description('A shorter title for menus and breadcrumbs.'),
            'meta_title' => $schema->string()->description('The title for search engines, at most 160 characters.'),
            'meta_description' => $schema->string()->description('The description for search engines, at most 320 characters.'),
            'og_image_id' => $schema->integer()->description('The id of the share image, from media_list.'),
            'canonical_url' => $schema->string()->description('A full canonical address starting with https://, only when this page duplicates another one.'),
            'noindex' => $schema->boolean()->description('True to keep this page out of search engines.'),
            'schema_type' => $schemaTypes === [] ? $schemaType : $schemaType->enum($schemaTypes),
        ];
    }

    protected function blockTypesSummary(): string
    {
        return app(BlockTypeSummary::class)->text();
    }

    protected function unknownBlockType(string $key): Response
    {
        return Response::error(sprintf('The block type "%s" does not exist or is disabled. Use one of: %s.', $key, implode(', ', array_keys(app(BlockRegistry::class)->all()))));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validateBlockData(BlockType $type, array $data): array
    {
        return Validator::validate($data, $type->rules());
    }

    protected function lockedBlock(Page $page, int $blockId): ?Block
    {
        return Block::query()
            ->where('page_id', $page->id)
            ->lockForUpdate()
            ->find($blockId);
    }

    protected function blockNotFound(int $blockId): Response
    {
        return Response::error(sprintf('Block %d was not found. Use page_get to see the block ids of a page.', $blockId));
    }
}
