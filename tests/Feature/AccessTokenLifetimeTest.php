<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccessTokenLifetimeTest extends TestCase
{
    use DatabaseTransactions;

    private function login(User $user): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => 'password123'])
            ->assertOk()->json('data.token');
        // Each real request is a fresh app; forget the user the login request signed in.
        $this->app['auth']->forgetGuards();

        return $token;
    }

    private function user(int $role): User
    {
        return User::factory()->create(['role' => $role, 'password' => bcrypt('password123')]);
    }

    #[Test]
    public function customers_stay_signed_in_for_90_days_and_admins_for_14()
    {
        $customer = PersonalAccessToken::findToken($this->login($this->user(User::ROLE_USER)));
        $admin = PersonalAccessToken::findToken($this->login($this->user(User::ROLE_ADMIN)));

        $this->assertEqualsWithDelta(90, now()->diffInDays($customer->expires_at), 0.1);
        $this->assertEqualsWithDelta(14, now()->diffInDays($admin->expires_at), 0.1);
    }

    #[Test]
    public function using_the_shop_moves_the_expiry_forward()
    {
        $plain = $this->login($this->user(User::ROLE_USER));
        PersonalAccessToken::findToken($plain)->forceFill(['expires_at' => now()->addDays(3)])->save();

        $this->withToken($plain)->getJson('/api/v1/auth/me')->assertOk();

        $this->assertGreaterThan(80, now()->diffInDays(PersonalAccessToken::findToken($plain)->expires_at));
    }

    #[Test]
    public function an_expired_token_gets_401_on_public_pages_instead_of_guest_prices()
    {
        $plain = $this->login($this->user(User::ROLE_STYLIST));
        PersonalAccessToken::findToken($plain)->forceFill(['expires_at' => now()->subMinute()])->save();

        $product = Product::factory()->create([
            'brand_id' => Brand::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
        ]);

        $this->withToken($plain)->getJson("/api/v1/products/{$product->id}")
            ->assertStatus(401)->assertJsonPath('code', 'session_expired');
        $this->withToken($plain)->getJson('/api/v1/features')->assertStatus(401);
    }

    #[Test]
    public function an_old_token_does_not_block_signing_in_again()
    {
        $user = $this->user(User::ROLE_USER);

        $this->withToken('1|not-a-real-token')
            ->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => 'password123'])
            ->assertOk();
    }

    #[Test]
    public function changing_the_password_signs_out_other_devices_only()
    {
        $user = $this->user(User::ROLE_USER);
        $phone = $this->login($user);
        $laptop = $this->login($user);

        $this->withToken($laptop)->putJson('/api/v1/auth/password', [
            'current_password' => 'password123',
            'password' => 'newpassword456',
            'password_confirmation' => 'newpassword456',
        ])->assertOk();

        $this->assertNull(PersonalAccessToken::findToken($phone));
        $this->assertNotNull(PersonalAccessToken::findToken($laptop));
    }

    #[Test]
    public function sign_out_of_other_devices_keeps_this_one()
    {
        $user = $this->user(User::ROLE_USER);
        $phone = $this->login($user);
        $laptop = $this->login($user);

        $this->withToken($laptop)->postJson('/api/v1/auth/logout-others')
            ->assertOk()->assertJsonPath('data.signed_out', 1);

        $this->assertNull(PersonalAccessToken::findToken($phone));
        $this->assertNotNull(PersonalAccessToken::findToken($laptop));
    }

    #[Test]
    public function an_ai_assistant_token_cannot_be_used_as_an_admin_key()
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $plain = $admin->createToken(\App\Support\AccessTokens::AI_PREFIX . 'laptop', ['*'], now()->addYear())->plainTextToken;

        $this->withToken($plain)->getJson('/api/v1/admin/orders')->assertStatus(403);
        $this->withToken($plain)->getJson('/api/v1/auth/me')->assertStatus(403);
    }
}
