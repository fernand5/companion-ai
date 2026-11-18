<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\UpdateLocaleRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiMessages;
use App\Support\AppLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $user = User::create([
            'name' => $request->string('name'),
            'email' => $request->string('email'),
            'password' => Hash::make($request->string('password')),
            'timezone' => $request->string('timezone')->toString() ?: null,
            'locale' => AppLocale::fromSupplied($request->input('locale'))?->value ?? AppLocale::DEFAULT->value,
        ]);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ], 201);
    }

    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => [ApiMessages::loginFailed()],
            ]);
        }

        // Refreshed on every login (not just captured once at registration)
        // so a user who travels or whose browser was previously misdetected
        // self-corrects the next time they sign in.
        if ($request->filled('timezone')) {
            $user->update(['timezone' => $request->string('timezone')->toString()]);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => ApiMessages::loggedOut()]);
    }

    public function me(Request $request)
    {
        return new UserResource($request->user());
    }

    /**
     * Saves the language the user reads the application in. It lives on the user, not on the fitness
     * profile, so it works before a profile exists. It changes wording only; nothing here touches
     * the plan, the profile or the coaching voice. Login deliberately never writes it: a language
     * picked on the sign-in screen must not replace the one the account already has.
     */
    public function updateLocale(UpdateLocaleRequest $request)
    {
        $user = $request->user();
        $user->update(['locale' => $request->validated('locale')]);

        return new UserResource($user->refresh());
    }
}
