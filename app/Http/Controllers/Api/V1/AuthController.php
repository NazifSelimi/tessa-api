<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\ProfileUpdateRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class AuthController extends Controller
{
    private const SOCIAL_PROVIDERS = ['google', 'facebook'];

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
        $user->address = $data['address'];
        $user->city = $data['city'];
        $user->postcode = $data['postcode'];
        $user->password = Hash::make($data['password']);
        $user->save();

        $token = $user->createToken('api')->plainTextToken;

        return ApiResponse::ok([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    public function socialRedirect(Request $request, string $provider): RedirectResponse
    {
        if (!$this->isSupportedSocialProvider($provider)) {
            return $this->redirectSocialError($provider, 'Unsupported social provider.');
        }

        $state = Str::random(64);

        Cache::put(
            $this->socialStateCacheKey($provider, $state),
            [
                'intent' => $request->query('intent', 'login'),
                'redirect' => $this->normalizeFrontendRedirect($request->query('redirect', '/')),
                'account_type' => $request->query('account_type', 'customer'),
            ],
            now()->addMinutes(10)
        );

        return redirect()->away($this->buildSocialAuthorizationUrl($provider, $state));
    }

    public function socialCallback(Request $request, string $provider): RedirectResponse
    {
        if (!$this->isSupportedSocialProvider($provider)) {
            return $this->redirectSocialError($provider, 'Unsupported social provider.');
        }

        $providerError = $request->query('error_description') ?: $request->query('error');
        if ($providerError) {
            return $this->redirectSocialError($provider, (string) $providerError);
        }

        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($state === '' || $code === '') {
            return $this->redirectSocialError($provider, 'Missing OAuth callback parameters.');
        }

        $stateData = Cache::pull($this->socialStateCacheKey($provider, $state));

        if (!is_array($stateData)) {
            return $this->redirectSocialError($provider, 'Authentication session expired. Please try again.');
        }

        try {
            $socialProfile = $this->fetchSocialUserProfile($provider, $code);
            [$user, $isNewUser] = $this->resolveSocialUser($provider, $socialProfile);
            $token = $user->createToken($provider . '-oauth')->plainTextToken;

            return $this->redirectSocialSuccess(
                $provider,
                $token,
                $user,
                [
                    'intent' => (string) ($stateData['intent'] ?? 'login'),
                    'redirect' => $this->normalizeFrontendRedirect($stateData['redirect'] ?? '/'),
                    'account_type' => (string) ($stateData['account_type'] ?? 'customer'),
                    'mode' => $isNewUser ? 'register' : 'login',
                ]
            );
        } catch (Throwable $e) {
            Log::warning('Social authentication failed', [
                'provider' => $provider,
                'message' => $e->getMessage(),
            ]);

            return $this->redirectSocialError($provider, $e->getMessage());
        }
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

        if (array_key_exists('preferred_locale', $data)) {
            $user->preferred_locale = $data['preferred_locale'];
        }

        $user->save();

        return ApiResponse::ok(new UserResource($user));
    }

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $user = User::where('email', $request->email)->first();

        if ($user) {
            $token = Str::random(64);
            
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'email' => $user->email,
                    'token' => Hash::make($token),
                    'created_at' => now(),
                ]
            );

            Mail::to($user->email)->send(new PasswordResetMail($user, $token));
        }

        // Always return success to prevent email enumeration
        return ApiResponse::ok(
            null,
            200,
            [],
            'If an account exists with that email, a reset link has been sent.'
        );
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        $resetRecord = DB::table('password_reset_tokens')
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
        DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->delete();

        return ApiResponse::ok(null, 200, [], 'Password has been reset successfully');
    }

    private function isSupportedSocialProvider(string $provider): bool
    {
        return in_array($provider, self::SOCIAL_PROVIDERS, true);
    }

    private function socialStateCacheKey(string $provider, string $state): string
    {
        return 'social-auth:' . $provider . ':' . $state;
    }

    private function normalizeFrontendRedirect(?string $redirect): string
    {
        $redirect = trim((string) $redirect);

        if ($redirect === '') {
            return '/';
        }

        $parts = parse_url($redirect);
        $path = $parts['path'] ?? $redirect;

        if (!str_starts_with($path, '/')) {
            $path = '/' . ltrim($path, '/');
        }

        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $path . $query . $fragment;
    }

    private function buildSocialAuthorizationUrl(string $provider, string $state): string
    {
        $redirectUri = $this->socialRedirectUri($provider);

        return match ($provider) {
            'google' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id' => config('services.google.client_id'),
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'state' => $state,
                'prompt' => 'select_account',
            ]),
            'facebook' => sprintf(
                'https://www.facebook.com/%s/dialog/oauth?%s',
                config('services.facebook.graph_version', 'v23.0'),
                http_build_query([
                    'client_id' => config('services.facebook.client_id'),
                    'redirect_uri' => $redirectUri,
                    'response_type' => 'code',
                    'scope' => 'email,public_profile',
                    'state' => $state,
                ])
            ),
        };
    }

    private function fetchSocialUserProfile(string $provider, string $code): array
    {
        return match ($provider) {
            'google' => $this->fetchGoogleUserProfile($code),
            'facebook' => $this->fetchFacebookUserProfile($code),
        };
    }

    private function fetchGoogleUserProfile(string $code): array
    {
        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => $this->socialRedirectUri('google'),
            'grant_type' => 'authorization_code',
        ]);

        if ($tokenResponse->failed()) {
            throw new \RuntimeException('Google login could not be completed.');
        }

        $accessToken = (string) $tokenResponse->json('access_token');
        if ($accessToken === '') {
            throw new \RuntimeException('Google did not return an access token.');
        }

        $profileResponse = Http::withToken($accessToken)
            ->acceptJson()
            ->get('https://openidconnect.googleapis.com/v1/userinfo');

        if ($profileResponse->failed()) {
            throw new \RuntimeException('Google profile could not be loaded.');
        }

        $profile = $profileResponse->json();

        return [
            'id' => (string) ($profile['sub'] ?? ''),
            'email' => (string) ($profile['email'] ?? ''),
            'first_name' => (string) ($profile['given_name'] ?? ''),
            'last_name' => (string) ($profile['family_name'] ?? ''),
            'name' => (string) ($profile['name'] ?? ''),
            'avatar' => (string) ($profile['picture'] ?? ''),
        ];
    }

    private function fetchFacebookUserProfile(string $code): array
    {
        $graphVersion = config('services.facebook.graph_version', 'v23.0');

        $tokenResponse = Http::acceptJson()->get(
            sprintf('https://graph.facebook.com/%s/oauth/access_token', $graphVersion),
            [
                'client_id' => config('services.facebook.client_id'),
                'client_secret' => config('services.facebook.client_secret'),
                'redirect_uri' => $this->socialRedirectUri('facebook'),
                'code' => $code,
            ]
        );

        if ($tokenResponse->failed()) {
            throw new \RuntimeException('Facebook login could not be completed.');
        }

        $accessToken = (string) $tokenResponse->json('access_token');
        if ($accessToken === '') {
            throw new \RuntimeException('Facebook did not return an access token.');
        }

        $profileResponse = Http::acceptJson()->get(
            sprintf('https://graph.facebook.com/%s/me', $graphVersion),
            [
                'fields' => 'id,name,email,first_name,last_name,picture.type(large)',
                'access_token' => $accessToken,
            ]
        );

        if ($profileResponse->failed()) {
            throw new \RuntimeException('Facebook profile could not be loaded.');
        }

        $profile = $profileResponse->json();

        return [
            'id' => (string) ($profile['id'] ?? ''),
            'email' => (string) ($profile['email'] ?? ''),
            'first_name' => (string) ($profile['first_name'] ?? ''),
            'last_name' => (string) ($profile['last_name'] ?? ''),
            'name' => (string) ($profile['name'] ?? ''),
            'avatar' => (string) data_get($profile, 'picture.data.url', ''),
        ];
    }

    private function resolveSocialUser(string $provider, array $profile): array
    {
        $providerId = trim((string) ($profile['id'] ?? ''));
        $email = trim(Str::lower((string) ($profile['email'] ?? '')));

        if ($providerId === '') {
            throw new \RuntimeException('The provider did not return a valid account identifier.');
        }

        if ($email === '') {
            throw new \RuntimeException('Your ' . ucfirst($provider) . ' account did not provide an email address.');
        }

        $providerField = $provider . '_id';

        $user = User::withTrashed()
            ->where($providerField, $providerId)
            ->orWhere('email', $email)
            ->first();

        $isNewUser = false;

        if ($user && filled($user->{$providerField}) && $user->{$providerField} !== $providerId) {
            throw new \RuntimeException('This email is already linked to another ' . ucfirst($provider) . ' account.');
        }

        if (!$user) {
            $user = new User();
            $user->email = $email;
            $user->phone = '';
            $user->address = '';
            $user->city = '';
            $user->postcode = '';
            $user->password = Hash::make(Str::random(40));
            $user->role = User::ROLE_USER;
            $isNewUser = true;
        }

        if ($user->trashed()) {
            $user->restore();
        }

        $name = trim((string) ($profile['name'] ?? ''));
        $firstName = trim((string) ($profile['first_name'] ?? ''));
        $lastName = trim((string) ($profile['last_name'] ?? ''));

        if ($firstName === '' && $name !== '') {
            $nameParts = preg_split('/\s+/', $name, 2) ?: [];
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';
        }

        if (($user->first_name ?? '') === '') {
            $user->first_name = $firstName;
        }

        if (($user->last_name ?? '') === '') {
            $user->last_name = $lastName;
        }

        if ($name !== '' && property_exists($user, 'name') && ($user->name ?? '') === '') {
            $user->name = $name;
        }

        $user->{$providerField} = $providerId;
        $user->email = $email;
        $user->email_verified_at = $user->email_verified_at ?? now();

        $avatar = trim((string) ($profile['avatar'] ?? ''));
        if ($avatar !== '') {
            $user->avatar = $avatar;
        }

        $user->save();

        return [$user, $isNewUser];
    }

    private function socialRedirectUri(string $provider): string
    {
        return (string) config('services.' . $provider . '.redirect');
    }

    private function frontendSocialCallbackUrl(): string
    {
        return rtrim((string) config('tessa.frontend_url'), '/') . (string) config('tessa.social_auth_callback_path');
    }

    private function redirectSocialSuccess(string $provider, string $token, User $user, array $meta): RedirectResponse
    {
        $userPayload = json_encode((new UserResource($user))->resolve());
        $userEncoded = rtrim(strtr(base64_encode($userPayload ?: '{}'), '+/', '-_'), '=');

        $query = http_build_query([
            'token' => $token,
            'user' => $userEncoded,
            'provider' => $provider,
            'intent' => $meta['intent'] ?? 'login',
            'account_type' => $meta['account_type'] ?? 'customer',
            'mode' => $meta['mode'] ?? 'login',
            'redirect' => $meta['redirect'] ?? '/',
        ]);

        return redirect()->away($this->frontendSocialCallbackUrl() . '?' . $query);
    }

    private function redirectSocialError(string $provider, string $message): RedirectResponse
    {
        $query = http_build_query([
            'error' => $message,
            'provider' => $provider,
        ]);

        return redirect()->away($this->frontendSocialCallbackUrl() . '?' . $query);
    }
}
