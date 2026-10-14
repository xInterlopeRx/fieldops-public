<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationSetupStep extends Model
{
    public const FIRST_ADMIN = 'first_admin';

    public const BWS_RUNTIME_READER = 'bws_runtime_reader';

    public const QUICKBOOKS_OAUTH = 'quickbooks_oauth';

    public const INITIAL_QBO_IMPORT = 'initial_qbo_import';

    public const GRAPH_VALIDATION = 'graph_validation';

    public const FAILURE_RECONCILIATION = 'failure_reconciliation';

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_PASSED = 'passed';

    public const STATUS_DEFERRED = 'deferred';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NOT_REQUIRED = 'not_required';

    /** @var list<string> */
    public const STEP_KEYS = [
        self::FIRST_ADMIN,
        self::BWS_RUNTIME_READER,
        self::QUICKBOOKS_OAUTH,
        self::INITIAL_QBO_IMPORT,
        self::GRAPH_VALIDATION,
        self::FAILURE_RECONCILIATION,
    ];

    protected $fillable = [
        'step_key',
        'status',
        'attempt_count',
        'last_attempted_at',
        'completed_at',
        'failure_code',
        'progress_current',
        'progress_total',
        'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'last_attempted_at' => 'datetime',
            'completed_at' => 'datetime',
            'progress_current' => 'integer',
            'progress_total' => 'integer',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
