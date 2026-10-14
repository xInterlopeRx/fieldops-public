<?php

namespace App\Services;

use App\Models\ApplicationSetupState;
use App\Models\ApplicationSetupStep;
use App\Models\InstallationClaim;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ApplicationSetupStepService
{
    private const MAX_PROGRESS = 1_000_000;

    public function begin(string $stepKey, User $actor): void
    {
        $this->updateStep($stepKey, function (ApplicationSetupStep $step) use ($actor): void {
            $step->forceFill([
                'status' => ApplicationSetupStep::STATUS_IN_PROGRESS,
                'attempt_count' => min(65535, $step->attempt_count + 1),
                'last_attempted_at' => now(),
                'completed_at' => null,
                'failure_code' => null,
                'progress_current' => null,
                'progress_total' => null,
                'actor_user_id' => $actor->getKey(),
            ]);
        });
    }

    public function pass(string $stepKey, User $actor): void
    {
        $this->updateStep($stepKey, function (ApplicationSetupStep $step) use ($actor): void {
            $step->forceFill([
                'status' => ApplicationSetupStep::STATUS_PASSED,
                'completed_at' => now(),
                'failure_code' => null,
                'actor_user_id' => $actor->getKey(),
            ]);
        });
    }

    public function fail(string $stepKey, User $actor, string $failureCode): void
    {
        $this->updateStep($stepKey, function (ApplicationSetupStep $step) use ($actor, $failureCode): void {
            $step->forceFill([
                'status' => ApplicationSetupStep::STATUS_FAILED,
                'last_attempted_at' => now(),
                'completed_at' => null,
                'failure_code' => preg_match('/^[a-z0-9_]{1,80}$/D', $failureCode) === 1
                    ? $failureCode
                    : 'setup_step_failed',
                'actor_user_id' => $actor->getKey(),
            ]);
        });
    }

    public function resetPending(string $stepKey, User $actor): void
    {
        if (! in_array($stepKey, [ApplicationSetupStep::GRAPH_VALIDATION], true)) {
            throw new InvalidArgumentException('This setup step cannot be reset to pending.');
        }

        $this->updateStep($stepKey, function (ApplicationSetupStep $step) use ($actor): void {
            $step->forceFill([
                'status' => ApplicationSetupStep::STATUS_PENDING,
                'last_attempted_at' => null,
                'completed_at' => null,
                'failure_code' => null,
                'progress_current' => null,
                'progress_total' => null,
                'actor_user_id' => $actor->getKey(),
            ]);
        });
    }

    public function deferBwsRuntimeReader(User $actor): void
    {
        $this->updateStep(ApplicationSetupStep::BWS_RUNTIME_READER, function (ApplicationSetupStep $step) use ($actor): void {
            $step->forceFill([
                'status' => ApplicationSetupStep::STATUS_DEFERRED,
                'completed_at' => now(),
                'failure_code' => null,
                'progress_current' => null,
                'progress_total' => null,
                'actor_user_id' => $actor->getKey(),
            ]);
        });
    }

    public function resumeBwsRuntimeReader(User $actor): void
    {
        $this->updateStep(ApplicationSetupStep::BWS_RUNTIME_READER, function (ApplicationSetupStep $step) use ($actor): void {
            if ($step->status !== ApplicationSetupStep::STATUS_DEFERRED) {
                throw new InvalidArgumentException('Only a deferred BWS runtime reader can be resumed.');
            }

            $step->forceFill([
                'status' => ApplicationSetupStep::STATUS_PENDING,
                'completed_at' => null,
                'actor_user_id' => $actor->getKey(),
            ]);
        });
    }

    public function resumeInitialImport(User $actor): void
    {
        $this->updateStep(ApplicationSetupStep::INITIAL_QBO_IMPORT, function (ApplicationSetupStep $step) use ($actor): void {
            if ($step->status !== ApplicationSetupStep::STATUS_DEFERRED) {
                throw new InvalidArgumentException('Only a deferred initial import can be resumed.');
            }

            $step->forceFill([
                'status' => ApplicationSetupStep::STATUS_PENDING,
                'completed_at' => null,
                'actor_user_id' => $actor->getKey(),
            ]);
        });
    }

    public function progress(string $stepKey, User $actor, int $current, int $total): void
    {
        if (! in_array($stepKey, [ApplicationSetupStep::INITIAL_QBO_IMPORT, ApplicationSetupStep::GRAPH_VALIDATION], true)
            || $current < 0
            || $total < 1
            || $current > $total
            || $total > self::MAX_PROGRESS) {
            throw new InvalidArgumentException('Setup progress is outside the allowed bounds.');
        }

        $this->updateStep($stepKey, function (ApplicationSetupStep $step) use ($actor, $current, $total): void {
            if ($step->status !== ApplicationSetupStep::STATUS_IN_PROGRESS) {
                $step->attempt_count = min(65535, $step->attempt_count + 1);
                $step->last_attempted_at = now();
            }

            $step->forceFill([
                'status' => ApplicationSetupStep::STATUS_IN_PROGRESS,
                'progress_current' => $current,
                'progress_total' => $total,
                'actor_user_id' => $actor->getKey(),
            ]);
        });
    }

    public function markFirstAdminReadyIfEligible(User $user): void
    {
        if (! ApplicationSetupState::query()->whereKey(1)->value('onboarding_required')) {
            return;
        }

        $claim = InstallationClaim::query()->whereKey(1)->first();

        if ($claim !== null
            && (string) $claim->claimed_by_user_id === (string) $user->getKey()
            && $user->status === 'active'
            && $user->hasVerifiedEmail()
            && $user->hasEnabledTwoFactorAuthentication()) {
            $this->pass(ApplicationSetupStep::FIRST_ADMIN, $user);
        }
    }

    /** @param callable(ApplicationSetupStep): void $update */
    private function updateStep(string $stepKey, callable $update): void
    {
        if (! in_array($stepKey, ApplicationSetupStep::STEP_KEYS, true)) {
            throw new InvalidArgumentException('Unknown application setup step.');
        }

        DB::transaction(function () use ($stepKey, $update): void {
            if (! ApplicationSetupState::query()->whereKey(1)->value('onboarding_required')) {
                return;
            }

            $step = ApplicationSetupStep::query()
                ->where('step_key', $stepKey)
                ->lockForUpdate()
                ->firstOrFail();
            $update($step);
            $step->save();
        });
    }
}
