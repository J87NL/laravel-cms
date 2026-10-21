<?php

declare(strict_types=1);

namespace J87NL\Cms\Cache;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use J87NL\Cms\Models\MediaAsset;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class FlushPageCache
{
    private bool $pending = false;

    public function __construct(
        private readonly PageCache $cache,
    ) {}

    public function saved(Model $model): void
    {
        $this->flushFor($model);
    }

    public function deleted(Model $model): void
    {
        $this->flushFor($model);
    }

    private function flushFor(Model $model): void
    {
        if ($model instanceof Media && $model->model_type !== (new MediaAsset)->getMorphClass()) {
            return;
        }

        if ($this->pending) {
            return;
        }

        $this->pending = true;

        DB::afterRollBack(function (): void {
            $this->pending = false;
        });

        DB::afterCommit(function (): void {
            $this->pending = false;
            $this->cache->flush();
        });
    }
}
