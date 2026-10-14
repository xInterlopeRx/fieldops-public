<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use Archiveable, HasFactory;

    protected $fillable = [
        'customer_id',
        'site_id',
        'contact_type',
        'source',
        'first_name',
        'middle_name',
        'last_name',
        'title',
        'suffix',
        'email',
        'phone',
        'alternate_phone',
        'mobile_phone',
        'fax',
        'website',
        'metadata',
    ];

    protected $hidden = [
        'outlook_contact_id',
        'outlook_contact_mailbox_id',
        'outlook_sync_status',
        'outlook_sync_requested_at',
        'outlook_last_synced_at',
        'outlook_sync_error',
    ];

    protected $casts = [
        'metadata' => 'array',
        'outlook_sync_requested_at' => 'datetime',
        'outlook_last_synced_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function methods(): HasMany
    {
        return $this->hasMany(ContactMethod::class);
    }

    public function workOrders(): BelongsToMany
    {
        return $this->belongsToMany(WorkOrder::class, 'contact_work_order')->withTimestamps();
    }
}
