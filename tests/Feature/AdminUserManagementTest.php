<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_stylist' => false,
        ]);
        
        $this->user = User::factory()->create([
            'role' => 'user',
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

        $stylist = User::factory()->create(['role' => 'stylist']);

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
            'role' => 'stylist',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'User updated successfully'
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'first_name' => 'Updated',
            'role' => 'stylist',
        ]);
    }

    public function test_admin_can_delete_user()
    {
        Sanctum::actingAs($this->admin);

        $deleteUser = User::factory()->create();

        $response = $this->deleteJson("/api/v1/admin/users/{$deleteUser->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('users', ['id' => $deleteUser->id]);
    }

    public function test_admin_cannot_delete_own_account()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->deleteJson("/api/v1/admin/users/{$this->admin->id}");

        $response->assertStatus(400);
    }
}
