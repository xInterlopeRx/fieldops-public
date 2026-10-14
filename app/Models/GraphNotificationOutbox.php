<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GraphNotificationOutbox extends Model
{
    use HasFactory;

    protected $table = 'graph_notification_outbox';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'work_order_id',
        'visit_id',
        'type',
        'recipient_email',
        'recipient_role',
        'event_key',
        'status',
        'attempts',
        'available_at',
        'attempted_at',
        'sent_at',
        'last_error',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'available_at' => 'datetime',
        'attempted_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}
