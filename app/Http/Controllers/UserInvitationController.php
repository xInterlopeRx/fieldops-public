<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserInvitation;
use App\Services\OnboardingReadinessService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class UserInvitationController extends Controller
{
    public function show(string $token, OnboardingReadinessService $onboarding): Response
    {
        abort_unless($onboarding->invitationsUnlocked(), 404);

        $invitation = $this->invitation($token);

        return Inertia::render('Auth/AcceptInvitation', [
            'token' => $token,
            'name' => $invitation->name,
            'email' => $invitation->email,
            'roles' => $invitation->roles ?? ['basic_user'],
        ]);
    }

    public function store(Request $request, string $token, OnboardingReadinessService $onboarding): RedirectResponse
    {
        abort_unless($onboarding->invitationsUnlocked(), 404);

        $invitation = $this->invitation($token);
        $data = $request->validate([
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $invitation->name,
            'email' => $invitation->email,
            'password' => Hash::make($data['password']),
            'status' => 'active',
            'invited_at' => $invitation->created_at,
            'invited_by' => $invitation->invited_by,
        ]);
        $user->syncRoles($invitation->roles ?? ['basic_user']);
        $invitation->update(['accepted_at' => now()]);

        event(new Registered($user));
        auth()->login($user);

        return to_route('verification.notice')
            ->with('status', 'Verify your email address to continue with FieldOps.');
    }

    private function invitation(string $token): UserInvitation
    {
        return UserInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();
    }
}
