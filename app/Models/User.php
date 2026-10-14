<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'email_verified_at', 'status', 'invited_at', 'invited_by'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'invited_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            if ($user->wasChanged([
                'password',
                'status',
                'two_factor_secret',
                'two_factor_confirmed_at',
            ])) {
                $user->trustedDevices()->delete();
            }
        });
    }

    public function isAdmin(): bool
    {
        return $this->status === 'active' && $this->can('admin.access');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(UserInvitation::class, 'invited_by');
    }

    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function createdTimeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class, 'created_by');
    }

    public function updatedTimeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class, 'updated_by');
    }

    public function assignedCustomers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_user')->withTimestamps();
    }

    public function assignedProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_user')
            ->withPivot('assigned_by_user_id')
            ->withTimestamps();
    }

    public function assignedWorkOrders(): BelongsToMany
    {
        return $this->belongsToMany(WorkOrder::class, 'work_order_user')
            ->withPivot('assigned_by_user_id');
    }

    public function assignedVisits(): BelongsToMany
    {
        return $this->belongsToMany(Visit::class, 'visit_user');
    }
}
