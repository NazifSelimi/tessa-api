<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\AuthController;
use App\Models\User;
use ReflectionMethod;
use Tests\TestCase;

class SocialAuthRedirectTest extends TestCase
{
    public function test_successful_social_login_returns_credentials_in_the_url_fragment(): void
    {
        $user = new User([
            'first_name' => 'Tessa',
            'last_name' => 'Customer',
            'email' => 'customer@example.test',
            'role' => User::ROLE_USER,
        ]);
        $user->id = 1;

        $response = $this->invokePrivate(
            app(AuthController::class),
            'redirectSocialSuccess',
            ['google', 'test-token', $user, ['redirect' => '/shop']]
        );

        $url = $response->getTargetUrl();

        $this->assertStringContainsString('/auth/social/callback#token=', $url);
        $this->assertStringNotContainsString('/auth/social/callback?token=', $url);
    }

    public function test_social_login_errors_are_also_kept_out_of_query_parameters(): void
    {
        $response = $this->invokePrivate(
            app(AuthController::class),
            'redirectSocialError',
            ['google', 'Provider denied access']
        );

        $this->assertStringContainsString('/auth/social/callback#error=', $response->getTargetUrl());
    }

    private function invokePrivate(object $object, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($object, $method);

        return $reflection->invokeArgs($object, $arguments);
    }
}
