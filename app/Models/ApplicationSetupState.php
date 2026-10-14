<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationSetupState extends Model
{
    public const QUICKBOOKS_IMPORT_MODE_API = 'quickbooks_api';

    public const QUICKBOOKS_IMPORT_MODE_CSV = 'csv_upload';

    public $incrementing = false;

    protected $fillable = [
        'onboarding_required',
        'quickbooks_import_mode',
        'quickbooks_sync_opted_in',
        'quickbooks_sync_opted_in_at',
        'quickbooks_sync_verification_required',
        'quickbooks_sync_enabled',
        'readiness_completed_at',
        'readiness_completed_by_user_id',
        'readiness_receipt_type',
    ];

    protected $attributes = [
        'quickbooks_import_mode' => self::QUICKBOOKS_IMPORT_MODE_API,
        'quickbooks_sync_opted_in' => false,
        'quickbooks_sync_verification_required' => false,
        'quickbooks_sync_enabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'onboarding_required' => 'boolean',
            'quickbooks_sync_opted_in' => 'boolean',
            'quickbooks_sync_opted_in_at' => 'datetime',
            'quickbooks_sync_verification_required' => 'boolean',
            'quickbooks_sync_enabled' => 'boolean',
            'readiness_completed_at' => 'datetime',
        ];
    }

    public function readinessCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'readiness_completed_by_user_id');
    }
}
