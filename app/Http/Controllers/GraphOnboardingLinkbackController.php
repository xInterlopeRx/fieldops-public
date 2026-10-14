<?php

namespace App\Http\Controllers;

use App\Http\Requests\VerifyMicrosoftGraphLinkbackRequest;
use App\Models\ApplicationSetupStep;
use App\Models\AuditEvent;
use App\Models\GraphOnboardingChallenge;
use App\Models\InstallationClaim;
use App\Services\ApplicationSetupStepService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class GraphOnboardingLinkbackController extends Controller
{
    public function show(Request $request, string $challenge): Response
    {
        $record = $this->validChallenge($challenge);

        $response = Inertia::render('Auth/GraphOnboardingLinkback', [
            'verified' => $record->status === GraphOnboardingChallenge::STATUS_VERIFIED,
            'verifyUrl' => $request->fullUrl(),
            'expiresAt' => $record->expires_at->toIso8601String(),
            'verifiedAt' => $record->verified_at?->toIso8601String(),
        ])->toResponse($request);

        return $response->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function verify(
        VerifyMicrosoftGraphLinkbackRequest $request,
        string $challenge,
        ApplicationSetupStepService $setupSteps,
    ): RedirectResponse {
        [$verifiedChallenge, $newlyVerified] = DB::transaction(function () use ($request, $challenge): array {
            $record = GraphOnboardingChallenge::query()
                ->whereKey($challenge)
                ->where('environment', app()->environment())
                ->lockForUpdate()
                ->firstOrFail();

            if (! hash_equals($record->token_hash, hash('sha256', $request->string('token')->toString()))) {
                abort(404);
            }

            if ($record->status === GraphOnboardingChallenge::STATUS_VERIFIED) {
                return [$record, false];
            }

            if (! in_array($record->status, [
                GraphOnboardingChallenge::STATUS_SENDING,
                GraphOnboardingChallenge::STATUS_SENT,
                GraphOnboardingChallenge::STATUS_UNKNOWN,
            ], true) || $record->expires_at->isPast()) {
                abort(404);
            }

            $user = $record->user;
            $target = $record->target;
            $claim = InstallationClaim::query()->whereKey(1)->first();

            if (! $user
                || ! $target
                || $user->status !== 'active'
                || ! $user->hasVerifiedEmail()
                || ! $user->hasEnabledTwoFactorAuthentication()
                || (string) $claim?->claimed_by_user_id !== (string) $user->getKey()
                || ! hash_equals($record->recipient_email_hash, hash('sha256', strtolower($user->email)))) {
                abort(404);
            }

            $record->forceFill([
                'status' => GraphOnboardingChallenge::STATUS_VERIFIED,
                'verified_at' => now(),
                'failure_code' => null,
            ])->save();

            AuditEvent::query()->create([
                'actor_user_id' => $user->getKey(),
                'subject_type' => $target->getMorphClass(),
                'subject_id' => $target->getKey(),
                'event' => 'graph_onboarding_linkback_verified',
                'summary' => $user->name.' verified the Microsoft Graph onboarding email linkback.',
                'metadata' => [
                    'environment' => $record->environment,
                    'target_id' => $record->target_id,
                    'challenge_id' => $record->getKey(),
                    'status' => GraphOnboardingChallenge::STATUS_VERIFIED,
                ],
                'created_at' => now(),
            ]);

            return [$record->fresh(), true];
        });

        if ($newlyVerified
            && ApplicationSetupStep::query()
                ->where('step_key', ApplicationSetupStep::GRAPH_VALIDATION)
                ->value('status') === ApplicationSetupStep::STATUS_FAILED) {
            $setupSteps->begin(ApplicationSetupStep::GRAPH_VALIDATION, $verifiedChallenge->user);
        }

        return redirect()->to($request->fullUrl(), 303)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function validChallenge(string $challenge): GraphOnboardingChallenge
    {
        $record = GraphOnboardingChallenge::query()
            ->whereKey($challenge)
            ->where('environment', app()->environment())
            ->firstOrFail();

        if ($record->status === GraphOnboardingChallenge::STATUS_VERIFIED) {
            return $record;
        }

        if (! in_array($record->status, [
            GraphOnboardingChallenge::STATUS_SENDING,
            GraphOnboardingChallenge::STATUS_SENT,
            GraphOnboardingChallenge::STATUS_UNKNOWN,
        ], true) || $record->expires_at->isPast()) {
            abort(404);
        }

        $user = $record->user;
        $claim = InstallationClaim::query()->whereKey(1)->first();

        if (! $user
            || $user->status !== 'active'
            || ! $user->hasVerifiedEmail()
            || ! $user->hasEnabledTwoFactorAuthentication()
            || (string) $claim?->claimed_by_user_id !== (string) $user->getKey()
            || ! hash_equals($record->recipient_email_hash, hash('sha256', strtolower($user->email)))) {
            abort(404);
        }

        return $record;
    }
}
