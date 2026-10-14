<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuickBooksInitialCustomerImport extends Model
{
    protected $table = 'quickbooks_initial_customer_imports';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_CUSTOMER_SITES_COMPLETE = 'customer_site_complete';

    public const STATUS_COMPLETE = 'complete';

    public const SOURCE_QUICKBOOKS_API = 'quickbooks_api';

    public const SOURCE_CSV_UPLOAD = 'csv_upload';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'environment',
        'source',
        'realm_id',
        'status',
        'next_start_position',
        'total_customers',
        'processed_customers',
        'imported_customers',
        'imported_sites',
        'failure_code',
        'started_by_user_id',
        'started_at',
        'customer_sites_completed_at',
        'source_payload',
        'source_sha256',
    ];

    protected $hidden = [
        'source_payload',
    ];

    protected function casts(): array
    {
        return [
            'next_start_position' => 'integer',
            'total_customers' => 'integer',
            'processed_customers' => 'integer',
            'imported_customers' => 'integer',
            'imported_sites' => 'integer',
            'started_at' => 'datetime',
            'customer_sites_completed_at' => 'datetime',
            'source_payload' => 'encrypted',
        ];
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function failures(): HasMany
    {
        return $this->hasMany(QuickBooksInitialCustomerImportFailure::class, 'import_id');
    }
}
