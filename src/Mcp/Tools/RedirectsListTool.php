<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Mcp\CmsPresenter;
use J87NL\Cms\Mcp\Concerns\InteractsWithCms;
use J87NL\Cms\Models\Redirect;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('redirects_list')]
#[Title('List CMS redirects')]
#[Description('Lists the redirects of the website, sorted by from path, with id, from_path, target, status code, source (manual, auto or import) and hit count. Filter with from to find redirects whose from path contains that text. Paginated: pass page for more. Change redirects with redirect_save and redirect_delete; both take effect on the live website immediately.')]
#[IsReadOnly]
#[IsDestructive(false)]
class RedirectsListTool extends Tool
{
    use InteractsWithCms;

    private const int PER_PAGE_DEFAULT = 50;

    private const int PER_PAGE_MAX = 200;

    public function __construct(private readonly CmsPresenter $presenter) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->authorizeRead($request, CmsPermission::Redirects);

        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'from' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', sprintf('max:%d', self::PER_PAGE_MAX)],
        ]);

        $from = Str::lower(trim((string) ($data['from'] ?? ''), " \t\n\r\0\x0B/"));

        $paginator = Redirect::query()
            ->when($from !== '', fn ($query) => $query->whereRaw("from_path like ? escape '!'", [$this->likePattern($from)]))
            ->orderBy('from_path')
            ->paginate((int) ($data['per_page'] ?? self::PER_PAGE_DEFAULT), ['*'], 'page', (int) ($data['page'] ?? 1));

        return Response::structured([
            'redirects' => array_values(array_map(
                fn (Redirect $redirect): array => $this->presenter->redirect($redirect),
                $paginator->items(),
            )),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Only redirects whose from path contains this text, for example "oude-pagina".'),
            'page' => $schema->integer()->description('The page of results, starting at 1.'),
            'per_page' => $schema->integer()->description(sprintf('Redirects per page, at most %d (default %d).', self::PER_PAGE_MAX, self::PER_PAGE_DEFAULT)),
        ];
    }
}
