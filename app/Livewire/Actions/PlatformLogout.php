<?php

namespace App\Livewire\Actions;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class PlatformLogout
{
    /**
     * Log the current platform user out of the application.
     */
    public function __invoke(): RedirectResponse
    {
        Auth::guard('platform')->logout();

        Session::invalidate();
        Session::regenerateToken();

        return redirect()->route('admin.login');
    }
}
