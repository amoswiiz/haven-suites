<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class AuthController
{
    public function form() { return view('auth.login'); }
    public function login(Request $request) {
        $credentials = $request->validate(['email'=>'required|email','password'=>'required|string']);
        if (!Auth::attempt([...$credentials,'active'=>true], $request->boolean('remember'))) {
            return back()->withErrors(['email'=>'The supplied login details are incorrect.'])->onlyInput('email');
        }
        $request->session()->regenerate();
        $request->session()->forget('url.intended');
        return redirect('/');
    }
    public function home(Request $request) {
        $tenants = $request->user()->tenants()->where('active', true)->get();
        if ($tenants->count() === 1) {
            app()->instance('currentTenant',$tenants->first());
            $landing=haven_landing(); app()->forgetInstance('currentTenant');
            return redirect($landing);
        }
        return view('auth.tenants', compact('tenants'));
    }
    public function logout(Request $request) {
        Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        return redirect('/login');
    }
}
