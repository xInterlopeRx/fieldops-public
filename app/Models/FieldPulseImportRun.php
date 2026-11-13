<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldPulseImportRun extends Model
{
    protected $table = 'fieldpulse_import_runs';

    /** @var list<string> */
    public const STAGES = ['customers', 'locations', 'notes', 'complete'];

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_CUSTOMER_SITES_COMPLETE = 'customer_site_complete';

    public const STATUS_FAILED = 'failed';

    public const FAILURE_RESOURCE_CUSTOMER = 'customer';

    public const FAILURE_RESOURCE_LOCATION = 'location';

    public const FAILURE_RESOURCE_PROJECT = 'project';

    public const FAILURE_RESOURCE_JOB = 'job';

    protected $fillable = [
        'environment',
        'status',
        'stage',
        'customers_page',
        'customers_total',
        'customers_imported',
        'sites_imported',
        'projects_imported',
        'contacts_imported',
        'jobs_imported',
        'notes_imported',
        'comments_imported',
        'failure_code',
        'started_by_user_id',
        'started_at',
        'customer_sites_completed_at',
        'document_path',
        'document_sha256',
        'document_bytes',
        'document_retained_at',
    ];

    protected function casts(): array
    {
        return [
            'customers_page' => 'integer',
            'customers_total' => 'integer',
            'customers_imported' => 'integer',
            'sites_imported' => 'integer',
            'projects_imported' => 'integer',
            'contacts_imported' => 'integer',
            'jobs_imported' => 'integer',
            'notes_imported' => 'integer',
            'comments_imported' => 'integer',
            'started_at' => 'datetime',
            'customer_sites_completed_at' => 'datetime',
            'document_bytes' => 'integer',
            'document_retained_at' => 'datetime',
        ];
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function failures(): HasMany
    {
        return $this->hasMany(FieldPulseImportFailure::class, 'import_id');
    }
}
