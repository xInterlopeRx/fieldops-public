<?php

namespace App\Http\Controllers;

use App\Http\Requests\OfferAdminRoleTransferRequest;
use App\Models\AdminRoleTransfer;
use App\Models\InstallationClaim;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminRoleTransferController extends Controller
{
    public function create(Request $request): Response
    {
        abort_unless($request->user()->hasRole('admin'), 403);

        $candidates = User::query()
            ->where('status', 'active')
            ->whereNotNull('email_verified_at')
            ->whereNotNull('two_factor_confirmed_at')
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'admin'))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'email']);

        return Inertia::render('Admin/AdminRoleTransfer', [
            'candidates' => $candidates,
        ]);
    }

    public function store(OfferAdminRoleTransferRequest $request, User $target, ActivityLogService $activityLog): RedirectResponse
    {
        /** @var User $source */
        $source = $request->user();
        abort_if($source->is($target), 422, 'Choose another active staff member for the Admin transfer.');
        abort_unless(
            $target->status === 'active'
                && $target->email_verified_at !== null
                && $target->two_factor_confirmed_at !== null
                && ! $target->hasRole('admin'),
            422,
            'The recipient must be an active, verified staff member with two-factor authentication enabled.',
        );

        $transfer = DB::transaction(function () use ($source, $target, $activityLog): AdminRoleTransfer {
            InstallationClaim::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $source = User::query()->lockForUpdate()->findOrFail($source->getKey());
            $target = User::query()->lockForUpdate()->findOrFail($target->getKey());

            $activeAdminIds = User::query()
                ->where('status', 'active')
                ->role('admin')
                ->lockForUpdate()
                ->pluck('id');

            abort_unless($activeAdminIds->count() === 1 && (int) $activeAdminIds->first() === (int) $source->getKey(), 409);

            $hasPendingTransfer = AdminRoleTransfer::query()
                ->whereNull('completed_at')
                ->where('expires_at', '>', now())
                ->where(function ($query) use ($source, $target): void {
                    $query->where('from_user_id', $source->getKey())
                        ->orWhere('to_user_id', $target->getKey());
                })
                ->exists();

            abort_if($hasPendingTransfer, 409, 'An Admin transfer is already pending for this user.');

            $transfer = AdminRoleTransfer::query()->create([
                'from_user_id' => $source->getKey(),
                'to_user_id' => $target->getKey(),
                'expires_at' => now()->addDay(),
            ]);
            $activityLog->record($source, 'admin_role_transfer_offered', [
                'transfer_id' => $transfer->getKey(),
                'target_user_id' => $target->getKey(),
            ], $source);

            return $transfer;
        });

        return back()->with(
            'admin_transfer_url',
            route('admin.admin-role-transfers.show', $transfer),
        );
    }

    public function show(Request $request, AdminRoleTransfer $adminRoleTransfer): Response
    {
        $this->authorizeRecipient($request->user(), $adminRoleTransfer);

        return Inertia::render('Admin/AdminRoleTransferAcceptance', [
            'transfer' => [
                'id' => $adminRoleTransfer->getKey(),
                'fromName' => $adminRoleTransfer->fromUser()->value('name'),
                'expiresAt' => $adminRoleTransfer->expires_at->toIso8601String(),
            ],
        ]);
    }

    public function accept(
        Request $request,
        AdminRoleTransfer $adminRoleTransfer,
        ActivityLogService $activityLog,
    ): RedirectResponse {
        /** @var User $recipient */
        $recipient = $request->user();
        $request->validate(['confirmation' => ['accepted']]);
        $this->authorizeRecipient($recipient, $adminRoleTransfer);

        DB::transaction(function () use ($recipient, $adminRoleTransfer, $activityLog): void {
            InstallationClaim::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $transfer = AdminRoleTransfer::query()->lockForUpdate()->findOrFail($adminRoleTransfer->getKey());
            $source = User::query()->lockForUpdate()->findOrFail($transfer->from_user_id);
            $target = User::query()->lockForUpdate()->findOrFail($transfer->to_user_id);

            abort_unless($transfer->isPending(), 410, 'This Admin transfer has expired or is no longer available.');
            abort_unless($target->is($recipient), 403);
            abort_unless(
                $source->status === 'active'
                    && $source->hasRole('admin')
                    && $target->status === 'active'
                    && $target->email_verified_at !== null
                    && $target->two_factor_confirmed_at !== null
                    && ! $target->hasRole('admin'),
                409,
                'The Admin transfer is no longer valid. Ask the current Admin to create a new offer.',
            );

            $activeAdminIds = User::query()
                ->where('status', 'active')
                ->role('admin')
                ->lockForUpdate()
                ->pluck('id');

            abort_unless($activeAdminIds->count() === 1 && (int) $activeAdminIds->first() === (int) $source->getKey(), 409);

            $source->removeRole('admin');
            $target->assignRole('admin');
            $transfer->forceFill([
                'accepted_at' => now(),
                'completed_at' => now(),
            ])->save();

            $activityLog->record($target, 'admin_role_transfer_accepted', [
                'transfer_id' => $transfer->getKey(),
                'from_user_id' => $source->getKey(),
            ], $target);
            $activityLog->record($source, 'admin_role_transferred', [
                'transfer_id' => $transfer->getKey(),
                'to_user_id' => $target->getKey(),
            ], $target);
        });

        return to_route('calendar')->with('success', 'The Admin account role was transferred.');
    }

    private function authorizeRecipient(User $user, AdminRoleTransfer $transfer): void
    {
        abort_unless($user->status === 'active' && $user->getKey() === $transfer->to_user_id, 403);
        abort_unless($user->email_verified_at !== null && $user->two_factor_confirmed_at !== null, 403);
        abort_unless($transfer->isPending(), 410, 'This Admin transfer has expired or is no longer available.');
    }
}
