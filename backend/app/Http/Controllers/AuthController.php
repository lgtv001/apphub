<?php
namespace App\Http\Controllers;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $usuario = Usuario::where('email', $data['email'])->first();
        if (!$usuario || !Hash::check($data['password'], $usuario->password_hash)) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }
        if (!$usuario->activo) {
            return response()->json(['message' => 'Usuario inactivo'], 403);
        }
        $token = $usuario->createToken('auth-token')->plainTextToken;
        return response()->json(['token' => $token, 'usuario' => $usuario->only(['id', 'nombre', 'email', 'rol_global'])]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->only(['id', 'nombre', 'email', 'rol_global']));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        return response()->noContent();
    }
}
