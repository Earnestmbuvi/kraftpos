<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

         if ($user && !$user->is_active) {
           Auth::logout();
           $request->session()->invalidate();
           $request->session()->regenerateToken();

           return redirect()->route('login')
               ->with('status', 'Your account has been disabled. Please contact your manager.');
       }

        if ($user && !$user->is_super_admin) {
            if (!$user->business || !$user->business->isActive()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')
    ->with('status', 'Your business account is not active. Please contact support.');
            }
        }

        return $next($request);
    }
}
