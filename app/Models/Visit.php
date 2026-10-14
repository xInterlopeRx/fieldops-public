<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visit extends Model
{
    use Archiveable, HasFactory;

    protected $hidden = [
        'microsoft_graph_event_id',
        'microsoft_graph_event_tenant_id',
        'microsoft_graph_event_mailbox_id',
        'microsoft_graph_event_calendar_id',
        'microsoft_graph_transaction_id',
        'graph_sync_status',
        'graph_sync_error',
        'graph_synced_at',
        'rsvp_polled_at',
        'attendee_responses',
        'rsvp_attention_notified_at',
    ];

    protected $fillable = [
        'work_order_id',
        'visit_type',
        'initial_visit_id',
        'scheduled_at',
        'notes',
        'metadata',
        'duration_minutes',
        'scope',
        'microsoft_graph_event_id',
        'microsoft_graph_event_tenant_id',
        'microsoft_graph_event_mailbox_id',
        'microsoft_graph_event_calendar_id',
        'microsoft_graph_transaction_id',
        'graph_sync_status',
        'graph_sync_error',
        'graph_synced_at',
        'rsvp_polled_at',
        'attendee_responses',
        'rsvp_attention_notified_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'scheduled_at' => 'datetime',
        'graph_synced_at' => 'datetime',
        'rsvp_polled_at' => 'datetime',
        'attendee_responses' => 'array',
        'rsvp_attention_notified_at' => 'datetime',
    ];

    public function hasGraphEventTargetSnapshot(): bool
    {
        return $this->microsoft_graph_event_tenant_id !== null
            || $this->microsoft_graph_event_mailbox_id !== null
            || $this->microsoft_graph_event_calendar_id !== null;
    }

    public function graphEventTargetMatches(string $tenantId, string $mailboxId, ?string $calendarId): bool
    {
        return is_string($this->microsoft_graph_event_tenant_id)
            && is_string($this->microsoft_graph_event_mailbox_id)
            && hash_equals(strtolower($this->microsoft_graph_event_tenant_id), strtolower($tenantId))
            && hash_equals(strtolower($this->microsoft_graph_event_mailbox_id), strtolower($mailboxId))
            && $this->microsoft_graph_event_calendar_id === $calendarId;
    }

    public function scopeGraphEventTargetMismatch(
        Builder $query,
        string $tenantId,
        string $mailboxId,
        ?string $calendarId,
    ): Builder {
        return $query
            ->whereNotNull('microsoft_graph_event_id')
            ->where(function (Builder $owner) use ($tenantId, $mailboxId, $calendarId): void {
                $owner->whereNull('microsoft_graph_event_tenant_id')
                    ->orWhereNull('microsoft_graph_event_mailbox_id')
                    ->orWhereRaw('LOWER(microsoft_graph_event_tenant_id) <> ?', [strtolower($tenantId)])
                    ->orWhereRaw('LOWER(microsoft_graph_event_mailbox_id) <> ?', [strtolower($mailboxId)]);

                if ($calendarId === null) {
                    $owner->orWhereNotNull('microsoft_graph_event_calendar_id');
                } else {
                    $owner->orWhereNull('microsoft_graph_event_calendar_id')
                        ->orWhere('microsoft_graph_event_calendar_id', '<>', $calendarId);
                }
            });
    }

    /**
     * Get the work order associated with this visit.
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function initialVisit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'initial_visit_id');
    }

    public function revisits(): HasMany
    {
        return $this->hasMany(self::class, 'initial_visit_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * Get the users assigned to this visit.
     */
    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'visit_user');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WorkOrderAttachment::class);
    }

    public function jobItems(): HasMany
    {
        return $this->hasMany(JobItem::class)->orderBy('item_name_snapshot')->orderBy('id');
    }
}
