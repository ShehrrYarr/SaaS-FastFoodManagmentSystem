<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Each guard signs out on its own. The session itself is kept (only the
 * CSRF token is rotated) so a Super Admin session in the same browser
 * survives a shop logout and vice versa.
 */
class LogoutController extends Controller
{
    public function admin(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    public function shop(Request $request): RedirectResponse
    {
        Auth::guard('shop')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('shop.login');
    }
}
