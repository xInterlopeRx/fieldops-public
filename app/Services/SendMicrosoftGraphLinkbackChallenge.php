<?php

namespace App\Services;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\ApplicationSetupStep;
use App\Models\AuditEvent;
use App\Models\GraphOnboardingChallenge;
use App\Models\InstallationClaim;
use App\Models\MicrosoftGraphOnboardingTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SendMicrosoftGraphLinkbackChallenge
{
    private const LIFETIME_MINUTES = 30;

    public function __construct(
        private readonly MicrosoftGraphApiClient $graph,
        private readonly ApplicationSetupStepService $setupSteps,
    ) {}

    public function send(User $actor): GraphOnboardingChallenge
    {
        if (! config('services.microsoft_graph.onboarding_writes_enabled')) {
            throw ValidationException::withMessages([
                'confirm_send' => 'Microsoft Graph onboarding writes are disabled by deployment configuration.',
            ]);
        }

        $target = MicrosoftGraphOnboardingTarget::query()
            ->where('environment', app()->environment())
            ->firstOrFail();

        if (! $target->target_is_dedicated_shared_mailbox
            || ! in_array($target->validation_status, ['read_verified', 'read_verified_partial'], true)
            || $target->read_access_verified_at === null) {
            throw ValidationException::withMessages([
                'confirm_send' => 'Confirm the dedicated shared-mailbox target and complete its read-only preflight before sending an email linkback.',
            ]);
        }

        $claim = InstallationClaim::query()->whereKey(1)->firstOrFail();

        if ((string) $claim->claimed_by_user_id !== (string) $actor->getKey()
            || $actor->status !== 'active'
            || ! $actor->hasVerifiedEmail()
            || ! $actor->hasEnabledTwoFactorAuthentication()) {
            abort(403);
        }

        if (app()->environment('production') && parse_url((string) config('app.url'), PHP_URL_SCHEME) !== 'https') {
            throw ValidationException::withMessages([
                'confirm_send' => 'Graph email linkback requires the public HTTPS application URL.',
            ]);
        }

        $plainToken = Str::random(64);
        $challengeId = (string) Str::uuid();
        $expiresAt = now()->addMinutes(self::LIFETIME_MINUTES);
        $link = URL::temporarySignedRoute(
            'graph.onboarding.linkback.verify',
            $expiresAt,
            ['challenge' => $challengeId],
        ).'#token='.rawurlencode($plainToken);

        $challenge = DB::transaction(function () use ($actor, $challengeId, $expiresAt, $plainToken, $target): GraphOnboardingChallenge {
            GraphOnboardingChallenge::query()
                ->where('target_id', $target->getKey())
                ->whereIn('status', [
                    GraphOnboardingChallenge::STATUS_SENDING,
                    GraphOnboardingChallenge::STATUS_SENT,
                    GraphOnboardingChallenge::STATUS_UNKNOWN,
                ])
                ->update([
                    'status' => GraphOnboardingChallenge::STATUS_REPLACED,
                    'failure_code' => 'linkback_replaced_by_new_request',
                    'updated_at' => now(),
                ]);

            $challenge = GraphOnboardingChallenge::query()->create([
                'id' => $challengeId,
                'environment' => app()->environment(),
                'target_id' => $target->getKey(),
                'user_id' => $actor->getKey(),
                'status' => GraphOnboardingChallenge::STATUS_SENDING,
                'token_hash' => hash('sha256', $plainToken),
                'recipient_email_hash' => hash('sha256', strtolower($actor->email)),
                'expires_at' => $expiresAt,
            ]);

            $this->setupSteps->begin(ApplicationSetupStep::GRAPH_VALIDATION, $actor);
            $this->audit($actor, $target, $challenge, 'graph_onboarding_linkback_send_started');

            return $challenge;
        });

        try {
            $this->graph->postJsonForOnboardingTarget(
                $target,
                'users/'.rawurlencode($target->notification_mailbox_id).'/sendMail',
                [
                    'message' => [
                        'subject' => 'Confirm FieldOps integration setup',
                        'body' => [
                            'contentType' => 'Text',
                            'content' => "An administrator requested a FieldOps setup email verification. Follow this signed link to confirm delivery: {$link}\n\nNo customer or Job information is included. Ignore this message if you did not request it.",
                        ],
                        'toRecipients' => [[
                            'emailAddress' => ['address' => $actor->email],
                        ]],
                    ],
                    'saveToSentItems' => false,
                ],
            );
        } catch (MicrosoftGraphApiException $exception) {
            $deliveryUnknown = $exception->reason === 'graph_unavailable'
                || $exception->httpStatus >= 500;
            $status = $deliveryUnknown
                ? GraphOnboardingChallenge::STATUS_UNKNOWN
                : GraphOnboardingChallenge::STATUS_FAILED;
            $failureCode = $deliveryUnknown
                ? 'graph_linkback_delivery_unknown'
                : $this->safeFailureCode($exception->reason);
            GraphOnboardingChallenge::query()->whereKey($challenge->getKey())
                ->where('status', GraphOnboardingChallenge::STATUS_SENDING)
                ->update([
                    'status' => $status,
                    'failure_code' => $failureCode,
                    'updated_at' => now(),
                ]);
            $challenge->refresh();

            if ($challenge->status !== GraphOnboardingChallenge::STATUS_VERIFIED) {
                $this->setupSteps->fail(ApplicationSetupStep::GRAPH_VALIDATION, $actor, $challenge->failure_code);
            }

            $this->audit($actor, $target, $challenge, 'graph_onboarding_linkback_send_failed');

            throw new MicrosoftGraphApiException($failureCode, $exception->httpStatus, $exception->retryAfterSeconds);
        }

        GraphOnboardingChallenge::query()->whereKey($challenge->getKey())
            ->where('status', GraphOnboardingChallenge::STATUS_SENDING)
            ->update([
                'status' => GraphOnboardingChallenge::STATUS_SENT,
                'sent_at' => now(),
                'failure_code' => null,
                'updated_at' => now(),
            ]);
        $challenge->refresh();
        $this->audit($actor, $target, $challenge, 'graph_onboarding_linkback_send_accepted');

        return $challenge->fresh();
    }

    private function audit(
        User $actor,
        MicrosoftGraphOnboardingTarget $target,
        GraphOnboardingChallenge $challenge,
        string $event,
    ): void {
        AuditEvent::query()->create([
            'actor_user_id' => $actor->getKey(),
            'subject_type' => $target->getMorphClass(),
            'subject_id' => $target->getKey(),
            'event' => $event,
            'summary' => $actor->name.' ran the Microsoft Graph setup email linkback.',
            'metadata' => [
                'environment' => $challenge->environment,
                'target_id' => $challenge->target_id,
                'challenge_id' => $challenge->getKey(),
                'status' => $challenge->status,
                'failure_code' => $challenge->failure_code,
            ],
            'created_at' => now(),
        ]);
    }

    private function safeFailureCode(string $reason): string
    {
        return preg_match('/^[a-z0-9_]{1,80}$/D', $reason) === 1
            ? $reason
            : 'graph_linkback_failed';
    }
}
