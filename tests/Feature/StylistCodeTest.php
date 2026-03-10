<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\DistributorCode;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class StylistCodeTest extends TestCase
{
    use RefreshDatabase;

    protected $stylist;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->stylist = User::factory()->create([
            'first_name' => 'Jane',
            'role' => 'stylist',
            'is_stylist' => true,
        ]);
    }

    public function test_stylist_can_view_their_codes()
    {
        Sanctum::actingAs($this->stylist);

        DistributorCode::factory()->count(3)->create([
            'stylist_id' => $this->stylist->id,
        ]);

        $response = $this->getJson('/api/v1/stylist/codes');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'code', 'discountPercentage', 'usageCount', 'totalRevenue', 'isActive']
                ]
            ]);
    }

    public function test_stylist_can_generate_code()
    {
        Sanctum::actingAs($this->stylist);

        $response = $this->postJson('/api/v1/stylist/codes/generate', [
            'discountPercentage' => 15,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Distributor code generated successfully'
            ]);

        $this->assertDatabaseHas('distributor_codes', [
            'stylist_id' => $this->stylist->id,
            'discount_percentage' => 15,
        ]);

        // Check code format
        $code = $response->json('data.code');
        $this->assertStringContainsString('STYLIST', $code);
        $this->assertStringContainsString('JANE', $code);
        $this->assertStringContainsString((string) now()->year, $code);
    }

    public function test_stylist_can_view_code_stats()
    {
        Sanctum::actingAs($this->stylist);

        $code = DistributorCode::factory()->create([
            'stylist_id' => $this->stylist->id,
            'code' => 'STYLIST-TEST-2026',
        ]);

        $response = $this->getJson("/api/v1/stylist/codes/STYLIST-TEST-2026/stats");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['code', 'usageCount', 'totalRevenue', 'orders']
            ]);
    }

    public function test_stylist_can_toggle_code_status()
    {
        Sanctum::actingAs($this->stylist);

        $code = DistributorCode::factory()->create([
            'stylist_id' => $this->stylist->id,
            'code' => 'TEST-CODE',
            'is_active' => true,
        ]);

        $response = $this->putJson('/api/v1/stylist/codes/TEST-CODE', [
            'isActive' => false,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('distributor_codes', [
            'code' => 'TEST-CODE',
            'is_active' => false,
        ]);
    }

    public function test_stylist_cannot_access_another_stylist_code()
    {
        Sanctum::actingAs($this->stylist);

        $otherStylist = User::factory()->create(['role' => 'stylist']);
        $otherCode = DistributorCode::factory()->create([
            'stylist_id' => $otherStylist->id,
            'code' => 'OTHER-CODE',
        ]);

        $response = $this->getJson('/api/v1/stylist/codes/OTHER-CODE/stats');

        $response->assertStatus(404);
    }

    public function test_non_stylist_cannot_access_stylist_routes()
    {
        $user = User::factory()->create(['role' => 'user']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/stylist/codes');

        $response->assertStatus(403);
    }
}
