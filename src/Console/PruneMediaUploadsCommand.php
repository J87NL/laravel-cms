<?php

declare(strict_types=1);

namespace J87NL\Cms\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use J87NL\Cms\Models\MediaUploadSession;

class PruneMediaUploadsCommand extends Command
{
    protected $signature = 'cms:prune-media-uploads';

    protected $description = 'Delete chunked MCP image uploads that expired before they were finished, with their chunks, and chunk directories without an upload';

    public function handle(): int
    {
        $disk = Storage::disk(MediaUploadSession::disk());
        $pruned = 0;

        MediaUploadSession::query()
            ->expired()
            ->each(function (MediaUploadSession $session) use ($disk, &$pruned): void {
                $disk->deleteDirectory($session->directory());
                $session->delete();
                $pruned++;
            });

        $base = trim((string) config('cms.media.chunked_uploads.directory', 'cms-media-uploads'), '/');

        if ($base !== '') {
            $directories = $disk->directories($base);
            $known = MediaUploadSession::query()->pluck('id')->all();

            foreach ($directories as $directory) {
                if (Str::isUuid(basename($directory)) && ! in_array(basename($directory), $known, true)) {
                    $disk->deleteDirectory($directory);
                }
            }
        }

        $this->info(sprintf('Pruned %d expired media uploads.', $pruned));

        return self::SUCCESS;
    }
}
