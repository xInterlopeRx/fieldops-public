<?php

namespace App\Http\Controllers;

use App\Services\TrustedDeviceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TrustedDeviceController extends Controller
{
    public function destroyAll(Request $request, TrustedDeviceService $trustedDevices): RedirectResponse
    {
        $trustedDevices->revokeAll($request->user());

        return back()
            ->with('status', 'All trusted devices have been forgotten. They will need an authenticator code at the next sign in.')
            ->withCookie($trustedDevices->forgetCookie());
    }
}
