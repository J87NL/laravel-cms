<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use J87NL\Cms\Actions\SaveRedirect;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Enums\RedirectSource;
use J87NL\Cms\Mcp\CmsPresenter;
use J87NL\Cms\Mcp\Concerns\InteractsWithCms;
use J87NL\Cms\Models\Redirect;
use J87NL\Cms\Validation\RedirectRules;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('redirect_save')]
#[Title('Save CMS redirect')]
#[Description('Creates a redirect, or updates one when redirect_id is given. It takes effect on the live website immediately, so confirm the change with the user first. When updating, an omitted status_code keeps its current value. from_path is an old address on this site that is not a page; to is a path such as /nieuw or a full https:// address. Chains are collapsed and loops refused.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class RedirectSaveTool extends Tool
{
    use InteractsWithCms;

    public function __construct(private readonly CmsPresenter $presenter) {}

    public function handle(Request $request, SaveRedirect $saveRedirect): Response|ResponseFactory
    {
        $user = $this->authorizeWrite($request, CmsPermission::Redirects);

        if ($user instanceof Response) {
            return $user;
        }

        $input = $request->validate([
            'redirect_id' => ['nullable', 'integer'],
            'from_path' => ['required', ...RedirectRules::for('from_path')],
            'to' => ['required', ...RedirectRules::for('to')],
            'status_code' => ['nullable', 'integer', ...RedirectRules::for('status_code')],
        ]);

        $redirect = new Redirect;

        if (! is_null($input['redirect_id'] ?? null)) {
            $redirect = Redirect::query()
                ->find((int) $input['redirect_id']);

            if (is_null($redirect)) {
                return Response::error(sprintf('Redirect %d was not found. Use redirects_list to see the redirect ids.', (int) $input['redirect_id']));
            }
        }

        $created = ! $redirect->exists;

        $saveRedirect->handle(
            $redirect,
            (string) $input['from_path'],
            (string) $input['to'],
            (int) ($input['status_code'] ?? ($redirect->exists ? $redirect->status_code : 301)),
            RedirectSource::Manual,
        );

        return Response::structured([
            'message' => sprintf('Redirect from %s to %s %s; it is live now.', $redirect->fromUrl(), $redirect->to, $created ? 'created' : 'saved'),
            'redirect' => $this->presenter->redirect($redirect->refresh()),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'redirect_id' => $schema->integer()->description('The id of the redirect to update, from redirects_list. Leave out to create a new redirect.'),
            'from_path' => $schema->string()->description('The old address on this site, for example /oude-pagina.')->required(),
            'to' => $schema->string()->description('The target: a path starting with / or a full address starting with https://.')->required(),
            'status_code' => $schema->integer()->enum(Redirect::STATUS_CODES)->description('301 for permanent or 302 for temporary. Defaults to 301 for a new redirect and to the current code on an update.'),
        ];
    }
}
