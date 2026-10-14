<?php

namespace App\Services;

use App\Models\ApplicationSetupStep;
use App\Models\InstallationClaim;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class BootstrapInstallation
{
    public function __construct(private readonly ApplicationSetupStepService $setupSteps) {}

    /** @param array<string, mixed> $data */
    public function claim(array $data, SmtpBootstrapConfiguration $smtp): User
    {
        return DB::transaction(function () use ($data, $smtp): User {
            $claim = InstallationClaim::query()
                ->whereKey(1)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $claim->isAvailable()) {
                abort(404);
            }

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'password' => Hash::make($data['password']),
                'status' => 'active',
            ]);
            $user->syncRoles(['admin']);
            $smtp->store($data, $user);

            $claim->forceFill([
                'claimed_at' => now(),
                'claimed_by_user_id' => $user->getKey(),
            ])->save();

            $this->setupSteps->begin(ApplicationSetupStep::FIRST_ADMIN, $user);

            return $user;
        });
    }
}
