<?php

declare(strict_types=1);

namespace J87NL\Cms\Cache;

use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use J87NL\Cms\Models\MediaAsset;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use WeakMap;

class FlushPageCache
{
    /**
     * @var WeakMap<DatabaseTransactionRecord, true>
     */
    private WeakMap $scheduled;

    public function __construct(
        private readonly PageCache $cache,
    ) {
        $this->scheduled = new WeakMap;
    }

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
        if ($model instanceof Media && ! $this->isCmsMedia($model)) {
            return;
        }

        $transaction = $this->currentTransaction();

        if (is_null($transaction)) {
            $this->cache->flush();

            return;
        }

        for ($current = $transaction; ! is_null($current); $current = $current->parent) {
            if (isset($this->scheduled[$current])) {
                return;
            }
        }

        $this->scheduled[$transaction] = true;

        $transaction->addCallback(fn () => $this->cache->flush());
    }

    private function currentTransaction(): ?DatabaseTransactionRecord
    {
        $transactions = app()->bound('db.transactions') ? app('db.transactions') : null;

        if (! $transactions instanceof DatabaseTransactionsManager) {
            return null;
        }

        $transaction = $transactions->callbackApplicableTransactions()->last();

        return $transaction instanceof DatabaseTransactionRecord ? $transaction : null;
    }

    private function isCmsMedia(Media $media): bool
    {
        $type = (string) $media->model_type;

        return is_a(Relation::getMorphedModel($type) ?? $type, MediaAsset::class, true);
    }
}
