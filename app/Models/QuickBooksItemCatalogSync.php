<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickBooksItemCatalogSync extends Model
{
    protected $table = 'quickbooks_item_catalog_syncs';

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'environment',
        'realm_id',
        'status',
        'next_start_position',
        'total_items',
        'processed_items',
        'imported_items',
        'failure_code',
        'started_by_user_id',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'next_start_position' => 'integer',
            'total_items' => 'integer',
            'processed_items' => 'integer',
            'imported_items' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }
}
