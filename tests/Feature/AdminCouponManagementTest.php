<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Coupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class AdminCouponManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->admin = User::factory()->create([
            'role' => 'admin',
        ]);
    }

    public function test_admin_can_list_coupons()
    {
        Sanctum::actingAs($this->admin);

        Coupon::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/admin/coupons');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'code', 'type', 'value', 'isActive']
                ],
                'meta'
            ]);
    }

    public function test_admin_can_create_coupon()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/admin/coupons', [
            'code' => 'SAVE20',
            'type' => 'percentage',
            'value' => 20,
            'min_purchase' => 50,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'is_active' => true,
            'description' => 'Test coupon',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Coupon created successfully'
            ]);

        $this->assertDatabaseHas('coupons', [
            'code' => 'SAVE20',
            'type' => 'percentage',
            'value' => 20,
        ]);
    }

    public function test_coupon_code_must_be_uppercase()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/admin/coupons', [
            'code' => 'save20',
            'type' => 'percentage',
            'value' => 20,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('coupons', ['code' => 'SAVE20']);
    }

    public function test_admin_can_update_coupon()
    {
        Sanctum::actingAs($this->admin);

        $coupon = Coupon::factory()->create([
            'code' => 'OLDCODE',
            'value' => 10,
        ]);

        $response = $this->putJson("/api/v1/admin/coupons/{$coupon->id}", [
            'value' => 25,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('coupons', [
            'id' => $coupon->id,
            'value' => 25,
        ]);
    }

    public function test_admin_can_toggle_coupon_status()
    {
        Sanctum::actingAs($this->admin);

        $coupon = Coupon::factory()->create(['is_active' => true]);

        $response = $this->putJson("/api/v1/admin/coupons/{$coupon->id}/toggle");

        $response->assertStatus(200);
        $this->assertDatabaseHas('coupons', [
            'id' => $coupon->id,
            'is_active' => false,
        ]);
    }

    public function test_admin_can_delete_unused_coupon()
    {
        Sanctum::actingAs($this->admin);

        $coupon = Coupon::factory()->create(['used_count' => 0]);

        $response = $this->deleteJson("/api/v1/admin/coupons/{$coupon->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('coupons', ['id' => $coupon->id]);
    }

    public function test_admin_cannot_delete_used_coupon()
    {
        Sanctum::actingAs($this->admin);

        $coupon = Coupon::factory()->create(['used_count' => 5]);

        $response = $this->deleteJson("/api/v1/admin/coupons/{$coupon->id}");

        $response->assertStatus(400);
    }
}
