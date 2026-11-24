<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;

class AdminUserManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected $admin;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_stylist' => false,
        ]);
        
        $this->user = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_stylist' => false,
        ]);
    }

    public function test_admin_can_list_users()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/admin/users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'email', 'role', 'firstName', 'lastName']
                ],
                'meta' => ['current_page', 'per_page', 'total', 'last_page']
            ]);
    }

    public function test_non_admin_cannot_list_users()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/admin/users');

        $response->assertStatus(403);
    }

    public function test_admin_can_filter_users_by_role()
    {
        Sanctum::actingAs($this->admin);

        $stylist = User::factory()->create(['role' => User::ROLE_STYLIST]);

        $response = $this->getJson('/api/v1/admin/users?role=stylist');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        foreach ($data as $user) {
            $this->assertEquals('stylist', $user['role']);
        }
    }

    public function test_admin_can_search_users()
    {
        Sanctum::actingAs($this->admin);

        $searchUser = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $response = $this->getJson('/api/v1/admin/users?search=John');

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_admin_can_get_user_details()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson("/api/v1/admin/users/{$this->user->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['id', 'email', 'role', 'totalOrders', 'totalSpent']
            ]);
    }

    public function test_admin_can_update_user()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->putJson("/api/v1/admin/users/{$this->user->id}", [
            'first_name' => 'Updated',
            'role' => User::ROLE_STYLIST,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'User updated successfully'
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'first_name' => 'Updated',
            'role' => User::ROLE_STYLIST,
        ]);
    }

    public function test_admin_can_delete_user()
    {
        Sanctum::actingAs($this->admin);

        $deleteUser = User::factory()->create();

        $response = $this->deleteJson("/api/v1/admin/users/{$deleteUser->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('users', ['id' => $deleteUser->id]);
    }

    public function test_admin_cannot_delete_own_account()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->deleteJson("/api/v1/admin/users/{$this->admin->id}");

        $response->assertStatus(400);
    }

    /** #21: when an admin sets a new password, every device of that account is signed out. */
    public function test_an_admin_password_reset_signs_the_account_out_everywhere()
    {
        $phone = $this->user->createToken('api')->plainTextToken;
        $ai = $this->user->createToken(\App\Support\AccessTokens::AI_PREFIX . 'x')->plainTextToken;
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/admin/users/{$this->user->id}", ['password' => 'a-brand-new-one'])->assertOk();

        $this->assertNull(\Laravel\Sanctum\PersonalAccessToken::findToken($phone));
        $this->assertNull(\Laravel\Sanctum\PersonalAccessToken::findToken($ai));
        $this->app['auth']->forgetGuards();
        $this->withToken($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    /** #21: admins who change their own password stay signed in on the device they use. */
    public function test_an_admin_changing_their_own_password_keeps_this_device()
    {
        $here = $this->admin->createToken('api')->plainTextToken;
        $other = $this->admin->createToken('api')->plainTextToken;

        $this->withToken($here)->putJson("/api/v1/admin/users/{$this->admin->id}", ['password' => 'a-brand-new-one'])->assertOk();

        $this->assertNotNull(\Laravel\Sanctum\PersonalAccessToken::findToken($here));
        $this->assertNull(\Laravel\Sanctum\PersonalAccessToken::findToken($other));
    }

    /** #21: a phone set by the admin is also the phone the customer signs in with. */
    public function test_an_admin_phone_change_updates_the_sign_in_phone()
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('users', 'phone_login')) {
            $this->markTestSkipped('No phone_login column in this database.');
        }
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/admin/users/{$this->user->id}", ['phone' => '070 999 888'])->assertOk();
        $this->assertSame('070999888', $this->user->fresh()->phone_login);

        // A phone another account signs in with is refused.
        $other = User::factory()->create();
        $this->putJson("/api/v1/admin/users/{$other->id}", ['phone' => '070-999-888'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
    }

}
