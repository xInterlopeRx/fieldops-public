<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimelineEvent extends Model
{
    public const KIND_CUSTOMER_NOTE = 'customer_note';

    public const KIND_JOB_NOTE = 'job_note';

    public const KIND_FIELD_NOTE = 'field_note';

    public const KIND_COMMENT = 'comment';

    public const SOURCE_FIELDPULSE = 'fieldpulse';

    protected $fillable = [
        'customer_id',
        'site_id',
        'occurred_at',
        'kind',
        'body',
        'source',
        'source_record_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
