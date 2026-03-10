<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\ProfileUpdateRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $request->authenticate();

        $user = $request->user();

        $token = $user->createToken('api')->plainTextToken;

        return ApiResponse::ok([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    public function register(RegisterRequest $request)
    {
        $data = $request->validated();

        $user = new User();
        $user->first_name = $data['first_name'];
        $user->last_name = $data['last_name'];
        $user->email = $data['email'];
        $user->phone = $data['phone'];
        $user->address = '';
        $user->city = '';
        $user->postcode = '';
        $user->password = Hash::make($data['password']);
        $user->save();

        $token = $user->createToken('api')->plainTextToken;

        return ApiResponse::ok([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    public function me(Request $request)
    {
        return ApiResponse::ok(new UserResource($request->user()));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::ok(['logged_out' => true]);
    }

    public function updateProfile(ProfileUpdateRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();

        if (array_key_exists('first_name', $data)) {
            $user->first_name = $data['first_name'];
        }

        if (array_key_exists('last_name', $data)) {
            $user->last_name = $data['last_name'];
        }

        if (array_key_exists('email', $data)) {
            $user->email = $data['email'];
        }

        if (array_key_exists('phone', $data)) {
            $user->phone = $data['phone'];
        }

        if (array_key_exists('address', $data)) {
            $user->address = $data['address'];
        }

        if (array_key_exists('city', $data)) {
            $user->city = $data['city'];
        }

        if (array_key_exists('postcode', $data)) {
            $user->postcode = $data['postcode'];
        }

        $user->save();

        return ApiResponse::ok(new UserResource($user));
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            $token = \Illuminate\Support\Str::random(64);
            
            \Illuminate\Support\Facades\DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'email' => $user->email,
                    'token' => Hash::make($token),
                    'created_at' => now(),
                ]
            );

            // TODO: Send email with reset link containing $token
            // Mail::to($user->email)->send(new PasswordResetMail($token));
        }

        // Always return success to prevent email enumeration
        return ApiResponse::ok(
            null,
            200,
            [],
            'If an account exists with that email, a reset link has been sent.'
        );
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $resetRecord = \Illuminate\Support\Facades\DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$resetRecord || !Hash::check($request->token, $resetRecord->token)) {
            return ApiResponse::error('Invalid or expired reset token', 422);
        }

        // Check if token is not older than 60 minutes
        if (now()->diffInMinutes($resetRecord->created_at) > 60) {
            return ApiResponse::error('Reset token has expired', 422);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return ApiResponse::error('User not found', 404);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        // Delete the reset token
        \Illuminate\Support\Facades\DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->delete();

        return ApiResponse::ok(null, 200, [], 'Password has been reset successfully');
    }
}
