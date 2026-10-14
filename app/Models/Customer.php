<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    use Archiveable, HasFactory;

    protected $fillable = [
        'organization_id',
        'client_id',
        'source_customer_id',
        'import_source',
        'quickbooks_id',
        'company_name',
        'middle_name',
        'title',
        'suffix',
        'quickbooks_fully_qualified_name',
        'quickbooks_print_on_check_name',
        'quickbooks_customer_type_id',
        'quickbooks_customer_type_name',
        'quickbooks_custom_fields',
        'quickbooks_sync_token',
        'quickbooks_active',
        'quickbooks_parent_id',
        'quickbooks_level',
        'quickbooks_is_job',
        'quickbooks_bill_with_parent',
        'quickbooks_is_project',
        'preferred_delivery_method',
        'alternate_phone',
        'mobile_phone',
        'fax',
        'website',
        'quickbooks_created_at',
        'quickbooks_updated_at',
        'quickbooks_sync_status',
        'quickbooks_sync_error',
        'quickbooks_sync_requested_at',
        'quickbooks_last_synced_at',
        'quickbooks_write_request_id',
        'quickbooks_write_fields',
        'quickbooks_write_payload',
        'import_source',
        'invoice_ninja_id',
        'accounting_sync_status',
        'accounting_sync_error',
        'accounting_synced_at',
        'name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'address',
        'notes',
        'metadata',
    ];

    protected $hidden = [
        'quickbooks_id',
        'quickbooks_sync_token',
        'quickbooks_parent_id',
        'quickbooks_level',
        'quickbooks_is_job',
        'quickbooks_bill_with_parent',
        'quickbooks_is_project',
        'quickbooks_print_on_check_name',
        'quickbooks_custom_fields',
        'quickbooks_sync_status',
        'quickbooks_last_synced_at',
        'quickbooks_created_at',
        'quickbooks_updated_at',
        'quickbooks_sync_error',
        'quickbooks_sync_requested_at',
        'quickbooks_write_request_id',
        'quickbooks_write_fields',
        'quickbooks_write_payload',
    ];

    protected $casts = [
        'metadata' => 'array',
        'quickbooks_custom_fields' => 'array',
        'quickbooks_write_fields' => 'array',
        'quickbooks_write_payload' => 'array',
        'accounting_synced_at' => 'datetime',
        'quickbooks_active' => 'boolean',
        'quickbooks_is_job' => 'boolean',
        'quickbooks_bill_with_parent' => 'boolean',
        'quickbooks_is_project' => 'boolean',
        'quickbooks_created_at' => 'datetime',
        'quickbooks_updated_at' => 'datetime',
        'quickbooks_sync_requested_at' => 'datetime',
        'quickbooks_last_synced_at' => 'datetime',
    ];

    /**
     * Get the organization that owns this child company.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the sites belonging to this customer (child company).
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function emailAddresses(): HasMany
    {
        return $this->hasMany(CustomerEmailAddress::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(CustomerAttachment::class);
    }

    public function sharePointAttachments(): HasMany
    {
        return $this->hasMany(SharePointAttachment::class, 'company_id');
    }

    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'customer_user')
            ->withPivot('assigned_by_user_id')
            ->withTimestamps();
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function defaultBillingAddress(): HasOne
    {
        return $this->hasOne(CustomerAddress::class)
            ->where('address_type', CustomerAddress::TYPE_BILLING)
            ->where('is_default', true);
    }

    public function defaultShippingAddress(): HasOne
    {
        return $this->hasOne(CustomerAddress::class)
            ->where('address_type', CustomerAddress::TYPE_SHIPPING)
            ->where('is_default', true);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function workOrders(): HasManyThrough
    {
        return $this->hasManyThrough(WorkOrder::class, Site::class);
    }
}
