<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use J87NL\Cms\CmsPermission;
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

#[Name('redirect_delete')]
#[Title('Delete CMS redirect')]
#[Description('Deletes a redirect. The old address stops redirecting on the live website immediately and may show a not-found page, so confirm with the user first. This cannot be undone through these tools.')]
#[IsReadOnly(false)]
#[IsDestructive]
class RedirectDeleteTool extends Tool
{
    use InteractsWithCms;

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->authorizeWrite($request, CmsPermission::Redirects);

        if ($user instanceof Response) {
            return $user;
        }

        $input = $request->validate([
            'redirect_id' => ['required', 'integer'],
        ]);

        $redirect = Redirect::query()
            ->find((int) $input['redirect_id']);

        if (is_null($redirect)) {
            return Response::error(sprintf('Redirect %d was not found. Use redirects_list to see the redirect ids.', (int) $input['redirect_id']));
        }

        $redirect->delete();

        return Response::structured([
            'message' => sprintf('Redirect from %s to %s deleted.', $redirect->fromUrl(), $redirect->to),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'redirect_id' => $schema->integer()->description('The id of the redirect, from redirects_list.')->required(),
        ];
    }
}
