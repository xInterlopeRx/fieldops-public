<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    public const TYPE_INVENTORY = 'Inventory';

    public const TYPE_NON_INVENTORY = 'NonInventory';

    public const TYPE_SERVICE = 'Service';

    public const TYPE_GROUP = 'Group';

    public const TYPE_CATEGORY = 'Category';

    /** @var list<string> */
    public const SELECTABLE_TYPES = [
        self::TYPE_INVENTORY,
        self::TYPE_NON_INVENTORY,
        self::TYPE_SERVICE,
        self::TYPE_GROUP,
    ];

    protected $fillable = [
        'quickbooks_realm_id',
        'quickbooks_id',
        'name',
        'description',
        'type',
        'active',
        'last_synced_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function jobItems(): HasMany
    {
        return $this->hasMany(JobItem::class);
    }

    public function isSelectable(): bool
    {
        return $this->active && in_array($this->type, self::SELECTABLE_TYPES, true);
    }
}
