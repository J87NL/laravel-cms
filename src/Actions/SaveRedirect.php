<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Enums\RedirectSource;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Models\Redirect;
use J87NL\Cms\Redirects\RedirectPaths;

class SaveRedirect
{
    private const int MAX_HOPS = 20;

    public function __construct(
        private readonly RedirectPaths $paths,
    ) {}

    public function handle(Redirect $redirect, string $from, string $to, int $statusCode, RedirectSource $source): Redirect
    {
        return DB::transaction(function () use ($redirect, $from, $to, $statusCode, $source): Redirect {
            $fromPath = $this->paths->from($from);

            $this->assertUsableFrom($redirect, $fromPath);

            $target = $this->finalTarget($fromPath, $this->paths->to($to));

            $redirect->fill([
                'from_path' => $fromPath,
                'to' => $target,
                'status_code' => in_array($statusCode, Redirect::STATUS_CODES, true) ? $statusCode : 301,
                'source' => $source,
            ]);
            $redirect->save();

            $this->collapseChainsInto($redirect);

            return $redirect;
        });
    }

    public function forPathChange(string $oldPath, string $newPath): void
    {
        DB::transaction(function () use ($oldPath, $newPath): void {
            Redirect::query()
                ->where('from_path', $newPath)
                ->delete();

            if ($oldPath === '' || $oldPath === $newPath) {
                return;
            }

            $redirect = Redirect::query()
                ->firstOrNew([
                    'from_path' => $oldPath,
                ]);

            $redirect->fill([
                'to' => sprintf('/%s', $newPath),
                'status_code' => 301,
                'source' => RedirectSource::Auto,
            ]);
            $redirect->save();

            $this->collapseChainsInto($redirect);
        });
    }

    private function assertUsableFrom(Redirect $redirect, string $fromPath): void
    {
        if ($fromPath === '') {
            throw $this->invalid('from_path', 'cms::validation.redirect_from_home');
        }

        $pageExists = Page::query()
            ->where('path', $fromPath)
            ->exists();

        if ($pageExists) {
            throw $this->invalid('from_path', 'cms::validation.redirect_from_page', $fromPath);
        }

        $taken = Redirect::query()
            ->where('from_path', $fromPath)
            ->when($redirect->exists, fn ($query) => $query->whereKeyNot($redirect->getKey()))
            ->exists();

        if ($taken) {
            throw $this->invalid('from_path', 'cms::validation.redirect_taken', $fromPath);
        }
    }

    private function finalTarget(string $fromPath, string $to): string
    {
        $target = $to;

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $targetPath = $this->paths->targetPath($target);

            if ($targetPath === $fromPath) {
                throw $this->invalid('to', 'cms::validation.redirect_loop');
            }

            if (is_null($targetPath)) {
                return $target;
            }

            $next = Redirect::query()
                ->where('from_path', $targetPath)
                ->value('to');

            if (! is_string($next)) {
                return $target;
            }

            $target = $next;
        }

        throw $this->invalid('to', 'cms::validation.redirect_loop');
    }

    private function collapseChainsInto(Redirect $redirect): void
    {
        $target = sprintf('/%s', $redirect->from_path);

        $pointing = Redirect::query()
            ->whereKeyNot($redirect->id)
            ->where(fn ($query) => $query
                ->whereIn('to', [$target, sprintf('%s/', $target)])
                ->orWhere('to', 'like', sprintf('%s?%%', $target))
                ->orWhere('to', 'like', sprintf('%s#%%', $target)))
            ->get()
            ->filter(fn (Redirect $other): bool => $this->paths->targetPath($other->to) === $redirect->from_path);

        foreach ($pointing as $other) {
            if ($this->paths->targetPath($redirect->to) === $other->from_path) {
                $other->delete();

                continue;
            }

            $other->to = $redirect->to;
            $other->save();
        }
    }

    private function invalid(string $field, string $message, string $path = ''): ValidationException
    {
        return ValidationException::withMessages([
            $field => __($message, [
                'path' => sprintf('/%s', $path),
            ]),
        ]);
    }
}
