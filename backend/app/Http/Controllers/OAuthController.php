<?php
namespace App\Http\Controllers;

use App\Models\SolicitudAcceso;
use App\Models\Usuario;
use Laravel\Socialite\Facades\Socialite;

class OAuthController extends Controller
{
    private const ALLOWED = ['github', 'google'];

    public function redirect(string $provider)
    {
        abort_unless(in_array($provider, self::ALLOWED), 404);
        try {
            return Socialite::driver($provider)->redirect();
        } catch (\Throwable) {
            return redirect('/app/login.html?oauth_error=1');
        }
    }

    public function callback(string $provider)
    {
        abort_unless(in_array($provider, self::ALLOWED), 404);

        try {
            $social = Socialite::driver($provider)->user();
        } catch (\Throwable) {
            return redirect('/app/login.html?oauth_error=1');
        }

        $email = $social->getEmail();

        // If already a registered user, just redirect to login
        if ($email && Usuario::where('email', $email)->exists()) {
            return redirect('/app/login.html?oauth_exists=1');
        }

        // Upsert the join request (same provider+id → update, not duplicate)
        SolicitudAcceso::updateOrCreate(
            ['provider' => $provider, 'provider_id' => (string) $social->getId()],
            [
                'nombre'     => $social->getName() ?? $social->getNickname() ?? $email ?? 'Sin nombre',
                'email'      => $email ?? '',
                'avatar_url' => $social->getAvatar(),
                'estado'     => 'pendiente',
            ]
        );

        return redirect('/app/pending.html');
    }
}
