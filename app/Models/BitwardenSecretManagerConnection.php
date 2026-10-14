<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BitwardenSecretManagerConnection extends Model
{
    public const STATUS_UNVERIFIED = 'unverified';

    public const STATUS_SECRETS_VERIFIED = 'secrets_verified';

    public const STATUS_FAILED = 'failed';

    public const PROFILE_ALL_REQUIRED = 'all_required';

    public const PROFILE_GRAPH_ONLY = 'graph_only';

    /** @var list<string> */
    public const ALL_REQUIRED_SECRET_NAMES = [
        'INTUIT_CLIENT_ID',
        'INTUIT_CLIENT_SECRET',
        'MICROSOFT_GRAPH_CLIENT_ID',
        'MICROSOFT_GRAPH_CLIENT_SECRET',
    ];

    /** @var list<string> */
    public const GRAPH_SECRET_NAMES = [
        'MICROSOFT_GRAPH_CLIENT_ID',
        'MICROSOFT_GRAPH_CLIENT_SECRET',
    ];

    protected $fillable = [
        'environment',
        'organization_id',
        'project_id',
        'api_url',
        'identity_url',
        'verification_profile',
    ];

    protected $attributes = [
        'verification_profile' => self::PROFILE_ALL_REQUIRED,
    ];

    protected $hidden = [
        'runtime_access_token',
    ];

    protected $casts = [
        'runtime_access_token' => 'encrypted',
        'configured_at' => 'datetime',
        'last_verified_at' => 'datetime',
    ];

    public function configuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by_user_id');
    }

    public function secretWriteAttempts(): HasMany
    {
        return $this->hasMany(BwsSecretWriteAttempt::class, 'connection_id');
    }

    public function isVerifiedForCurrentEnvironment(): bool
    {
        $state = ApplicationSetupState::query()->whereKey(1)->first();
        $quickBooksOptedIn = $state === null
            || ! $state->onboarding_required
            || $state->quickbooks_sync_opted_in;
        $intuitProfileWasVerifiedAfterOptIn = ! ((bool) $state?->onboarding_required
            && $quickBooksOptedIn
            && $state->quickbooks_sync_verification_required);

        return $intuitProfileWasVerifiedAfterOptIn
            && $this->isVerifiedForQuickBooksOptIn($quickBooksOptedIn);
    }

    public function isVerifiedForGraph(): bool
    {
        return $this->isVerifiedForQuickBooksOptIn(false);
    }

    public function isVerifiedForImportMode(string $mode): bool
    {
        return match ($mode) {
            ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_API => $this->isVerifiedForQuickBooksOptIn(true),
            ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV => $this->isVerifiedForQuickBooksOptIn(false),
            default => false,
        };
    }

    /** @param list<string> $secretNames */
    public function isVerifiedForSecretNames(array $secretNames): bool
    {
        return array_diff($secretNames, self::GRAPH_SECRET_NAMES) === []
            ? $this->isVerifiedForGraph()
            : $this->isVerifiedForCurrentEnvironment();
    }

    public function isVerifiedForQuickBooksOptIn(bool $quickBooksOptedIn): bool
    {
        $apiUrl = config('services.bitwarden.api_url');
        $identityUrl = config('services.bitwarden.identity_url');
        $profileMatches = $this->verification_profile === self::PROFILE_ALL_REQUIRED
            || (! $quickBooksOptedIn && $this->verification_profile === self::PROFILE_GRAPH_ONLY);

        return $this->exists
            && $this->environment === app()->environment()
            && $this->verification_status === self::STATUS_SECRETS_VERIFIED
            && $profileMatches
            && is_string($apiUrl)
            && $this->api_url === $apiUrl
            && is_string($identityUrl)
            && $this->identity_url === $identityUrl
            && is_string($this->getRawOriginal('runtime_access_token'));
    }
}
