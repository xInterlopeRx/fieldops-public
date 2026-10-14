<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickBooksInitialCustomerImportFailure extends Model
{
    protected $table = 'quickbooks_initial_customer_import_failures';

    protected $fillable = [
        'import_id',
        'quickbooks_customer_id',
        'source_record_id',
        'source_row',
        'failure_code',
    ];

    protected function casts(): array
    {
        return [
            'source_row' => 'integer',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(QuickBooksInitialCustomerImport::class, 'import_id');
    }
}
