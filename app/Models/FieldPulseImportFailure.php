<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldPulseImportFailure extends Model
{
    protected $table = 'fieldpulse_import_failures';

    protected $fillable = [
        'import_id',
        'resource_type',
        'source_record_id',
        'failure_code',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(FieldPulseImportRun::class, 'import_id');
    }
}
