<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    use Archiveable;

    public const TYPE_BILLING = 'billing';

    public const TYPE_SHIPPING = 'shipping';

    public const SOURCE_QUICKBOOKS = 'quickbooks';

    public const SOURCE_CSV_IMPORT = 'csv_import';

    public const SOURCE_SITE = 'site';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'customer_id',
        'source_site_id',
        'address_type',
        'source',
        'label',
        'quickbooks_address_id',
        'line1',
        'line2',
        'line3',
        'line4',
        'line5',
        'city',
        'country_subdivision_code',
        'postal_code',
        'country',
        'latitude',
        'longitude',
        'is_default',
    ];

    protected $hidden = [
        'quickbooks_address_id',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sourceSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'source_site_id');
    }
}
