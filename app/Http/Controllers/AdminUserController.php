<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserInvitationRequest;
use App\Http\Requests\UpdateUserRolesRequest;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\ActivityLogService;
use App\Services\OnboardingReadinessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AdminUserController extends Controller
{
    public function index(Request $request, OnboardingReadinessService $onboarding): Response
    {
        return Inertia::render('Admin/Users', [
            'currentUserId' => $request->user()->id,
            'onboarding' => $onboarding->snapshot(),
            'users' => User::query()->with('roles:id,name')->orderBy('name')->get(['id', 'name', 'email', 'status', 'created_at'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status,
                    'created_at' => $user->created_at,
                    'roles' => $user->getRoleNames()->values()->all(),
                ]),
            'pendingInvitations' => UserInvitation::query()
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->latest()
                ->get(['id', 'name', 'email', 'roles', 'expires_at']),
        ]);
    }

    public function store(StoreUserInvitationRequest $request, OnboardingReadinessService $onboarding): RedirectResponse
    {
        if (! $onboarding->invitationsUnlocked()) {
            return to_route('admin.onboarding')
                ->with('error', 'Staff invitations remain locked until integration onboarding readiness is complete.');
        }

        $plainToken = Str::random(64);

        UserInvitation::create([
            'invited_by' => $request->user()->id,
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'roles' => $request->validated('roles'),
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDays(7),
        ]);

        return back()->with('invitation_url', route('invitation.accept', ['token' => $plainToken]));
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is($request->user()), 422, 'You cannot deactivate your own account.');
        $request->validate(['status' => ['required', 'in:active,inactive']]);
        $status = $request->string('status')->toString();

        if ($status === 'inactive' && $user->status === 'active' && $user->hasRole('admin')) {
            abort_unless($this->anotherActiveAdminExists($user), 422, 'At least one active Admin account must remain.');
        }

        if ($status === 'active' && $user->status === 'inactive' && $user->hasRole('admin')) {
            abort_unless(! $this->anotherActiveAdminExists($user), 422, 'There must be exactly one active Admin account. Transfer the Admin role to this user instead.');
        }

        if ($status === 'inactive' && $user->status === 'active' && $user->hasRole('hr')) {
            abort_unless($this->anotherActiveHrExists($user), 422, 'At least one active HR user must remain to manage staff access.');
        }

        $user->update(['status' => $status]);

        return back();
    }

    public function updateRoles(
        UpdateUserRolesRequest $request,
        User $user,
        ActivityLogService $activityLog,
    ): RedirectResponse {
        $securityGroups = $request->validated('security_groups');
        $notificationRoles = $request->validated('notification_roles', []);

        if ($user->status === 'active' && $user->hasRole('hr') && ! in_array('hr', $securityGroups, true)) {
            abort_unless($this->anotherActiveHrExists($user), 422, 'At least one active HR user must remain to manage staff access.');
        }

        $accountRole = $user->hasRole('admin') ? 'admin' : 'basic_user';
        $managedRoles = ['finance', 'hr', 'management', 'billing'];
        $previousManagedRoles = $user->getRoleNames()->intersect($managedRoles)->values()->all();
        $nextManagedRoles = array_values(array_unique([
            $accountRole,
            ...$notificationRoles,
            ...$securityGroups,
        ]));
        $user->syncRoles($nextManagedRoles);
        $nextManagedRoles = array_values(array_diff($nextManagedRoles, [$accountRole]));
        $addedRoles = array_values(array_diff($nextManagedRoles, $previousManagedRoles));
        $removedRoles = array_values(array_diff($previousManagedRoles, $nextManagedRoles));

        if ($addedRoles !== [] || $removedRoles !== []) {
            $activityLog->record($user, 'security_groups_updated', [
                'added_roles' => $addedRoles,
                'removed_roles' => $removedRoles,
            ], $request->user());
        }

        return back()->with('success', "Security groups updated for {$user->name}.");
    }

    private function anotherActiveHrExists(User $user): bool
    {
        return User::query()
            ->whereKeyNot($user->id)
            ->where('status', 'active')
            ->role('hr')
            ->exists();
    }

    private function anotherActiveAdminExists(User $user): bool
    {
        return User::query()
            ->whereKeyNot($user->id)
            ->where('status', 'active')
            ->role('admin')
            ->exists();
    }
}
