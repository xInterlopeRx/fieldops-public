<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait Archiveable
{
    public function newEloquentBuilder($query): ArchiveableBuilder
    {
        return new ArchiveableBuilder($query);
    }

    protected static function bootArchiveable(): void
    {
        static::addGlobalScope('active_archive_records', function (Builder $query): void {
            $query->where($query->getModel()->qualifyColumn('is_archived'), false);
        });

        static::deleting(function (Model $model): void {
            throw new \LogicException('Archiveable records must be archived, not deleted.');
        });
    }

    protected function initializeArchiveable(): void
    {
        $this->mergeCasts([
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
        ]);
        $this->setHidden(array_values(array_unique([
            ...$this->getHidden(),
            'is_archived',
            'archived_at',
            'archive_batch_id',
        ])));
    }

    public function scopeWithArchived(Builder $query): Builder
    {
        return $query->withoutGlobalScope('active_archive_records');
    }

    public function scopeOnlyArchived(Builder $query): Builder
    {
        $model = $query->getModel();

        return $query->withArchived()
            ->where($model->qualifyColumn('is_archived'), true);
    }

    public function archiveBatch(): BelongsTo
    {
        return $this->belongsTo(ArchiveBatch::class);
    }
}
