<?php

namespace App\Models;

use App\Enums\ContactMethodLabel;
use App\Enums\ContactMethodType;
use Database\Factories\ContactMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactMethod extends Model
{
    /** @use HasFactory<ContactMethodFactory> */
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'method_type',
        'label',
        'value',
        'is_primary',
    ];

    protected $casts = [
        'method_type' => ContactMethodType::class,
        'label' => ContactMethodLabel::class,
        'is_primary' => 'boolean',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
