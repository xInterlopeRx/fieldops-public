<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use Archiveable, HasFactory;

    protected $fillable = [
        'customer_id',
        'import_source',
        'name',
        'company_name',
        'preferred_delivery_method',
        'address',
        'metadata',
        'quickbooks_id',
        'quickbooks_sync_token',
        'quickbooks_active',
        'quickbooks_parent_id',
        'quickbooks_level',
        'quickbooks_is_job',
        'quickbooks_bill_with_parent',
        'quickbooks_is_project',
        'quickbooks_fully_qualified_name',
        'quickbooks_customer_type_id',
        'quickbooks_customer_type_name',
        'quickbooks_custom_fields',
        'quickbooks_print_on_check_name',
        'quickbooks_created_at',
        'quickbooks_updated_at',
        'quickbooks_write_request_id',
        'import_source',
    ];

    protected $hidden = [
        'quickbooks_id',
        'quickbooks_sync_token',
        'quickbooks_parent_id',
        'quickbooks_level',
        'quickbooks_is_job',
        'quickbooks_bill_with_parent',
        'quickbooks_is_project',
        'quickbooks_custom_fields',
        'quickbooks_print_on_check_name',
        'quickbooks_created_at',
        'quickbooks_updated_at',
        'quickbooks_write_request_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'quickbooks_custom_fields' => 'array',
        'quickbooks_active' => 'boolean',
        'quickbooks_is_job' => 'boolean',
        'quickbooks_bill_with_parent' => 'boolean',
        'quickbooks_is_project' => 'boolean',
        'quickbooks_created_at' => 'datetime',
        'quickbooks_updated_at' => 'datetime',
    ];

    /**
     * Get the customer (child company) that owns this site.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerAddresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class, 'source_site_id');
    }

    public function sharePointAttachments(): HasMany
    {
        return $this->hasMany(SharePointAttachment::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * Get the work orders associated with this site.
     */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class);
    }
}
