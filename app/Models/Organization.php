<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'tax_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    public function getHidden(): array
    {
        return ['metadata'];
    }

    /**
     * Get the child companies (customers) belonging to this organization.
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
