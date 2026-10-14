<?php

namespace App\Http\Controllers;

use App\Services\TrustedDeviceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LogoutAllDevicesController extends Controller
{
    public function __invoke(Request $request, TrustedDeviceService $trustedDevices): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        $sessionDriver = config('session.driver');

        abort_unless(in_array($sessionDriver, ['database', 'array'], true), 503, 'Sign out all requires the database session driver.');

        Auth::logoutOtherDevices($validated['password']);
        $trustedDevices->revokeAll($user);
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        if ($sessionDriver === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table'))
                ->where('user_id', $user->getKey())
                ->delete();
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login')
            ->with('status', 'You have been signed out on this device. Other signed-in devices will be required to sign in again.')
            ->withCookie($trustedDevices->forgetCookie());
    }
}
