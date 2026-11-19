<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\ActiveDirectoryAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class LoginController extends Controller
{
    public function __construct(protected ActiveDirectoryAuthenticator $directory) {}

    /**
     * Sign in the Windows session user, or show the Active Directory login form.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $hasSignedOut = $request->session()->pull('windows_sso_disabled', false);
        $windowsAccount = $hasSignedOut ? null : $this->directory->windowsAccountName($request);

        if ($windowsAccount !== null) {
            return $this->signIn($request, [
                'name' => $windowsAccount,
                'email' => $this->directory->emailFor($windowsAccount),
            ], updateName: false);
        }

        return view('auth.login');
    }

    /**
     * Validate the typed credentials against Active Directory.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        try {
            $profile = $this->directory->authenticate(
                $request->string('username')->toString(),
                $request->string('password')->toString(),
            );
        } catch (RuntimeException $exception) {
            Log::error($exception->getMessage());

            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => 'No fue posible contactar al directorio activo. Intente más tarde.']);
        }

        if ($profile === null) {
            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => 'Usuario o contraseña incorrectos.']);
        }

        return $this->signIn($request, $profile, updateName: true);
    }

    /**
     * Sign the user out and keep the Windows session from signing them back in.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('windows_sso_disabled', true);

        return redirect()->route('login');
    }

    /**
     * @param  array{name: string, email: string}  $profile
     */
    protected function signIn(Request $request, array $profile, bool $updateName): RedirectResponse
    {
        $user = User::firstOrCreate(
            ['email' => $profile['email']],
            ['name' => $profile['name'], 'password' => Str::random(40)],
        );

        if ($updateName && $user->name !== $profile['name']) {
            $user->update(['name' => $profile['name']]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
