<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\DistributorCode;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;

class StylistCodeTest extends TestCase
{
    use DatabaseTransactions;

    protected $stylist;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->stylist = User::factory()->create([
            'first_name' => 'Jane',
            'role' => User::ROLE_STYLIST,
            'is_stylist' => true,
        ]);
    }

    public function test_stylist_can_view_their_codes()
    {
        Sanctum::actingAs($this->stylist);

        DistributorCode::factory()->count(3)->create([
            'created_by' => $this->stylist->id,
        ]);

        $response = $this->getJson('/api/v1/stylist/codes');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'code', 'used', 'expiresAt', 'createdBy']
                ]
            ]);
    }

    public function test_stylist_can_generate_code()
    {
        Sanctum::actingAs($this->stylist);

        $response = $this->postJson('/api/v1/stylist/codes/generate');

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Invitation code generated successfully'
            ]);

        $this->assertDatabaseHas('stylist_invitation_codes', [
            'created_by' => $this->stylist->id,
        ]);

        // Check code format
        $code = $response->json('data.code');
        $this->assertStringContainsString('STYLIST', $code);
        $this->assertStringContainsString('JANE', $code);
    }

    public function test_stylist_can_view_code_stats()
    {
        Sanctum::actingAs($this->stylist);

        DistributorCode::factory()->create([
            'created_by' => $this->stylist->id,
            'code' => 'STYLIST-TEST-2026',
        ]);

        $response = $this->getJson("/api/v1/stylist/codes/STYLIST-TEST-2026/stats");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['code', 'used', 'usedBy', 'expiresAt', 'isExpired', 'createdAt']
            ]);
    }

    public function test_stylist_can_update_code()
    {
        Sanctum::actingAs($this->stylist);

        DistributorCode::factory()->create([
            'created_by' => $this->stylist->id,
            'code' => 'TEST-CODE',
        ]);

        $newExpiry = now()->addYear()->toDateTimeString();
        $response = $this->putJson('/api/v1/stylist/codes/TEST-CODE', [
            'expires_at' => $newExpiry,
        ]);

        $response->assertStatus(200);
    }

    public function test_stylist_cannot_access_another_stylist_code()
    {
        Sanctum::actingAs($this->stylist);

        $otherStylist = User::factory()->create(['role' => User::ROLE_STYLIST, 'is_stylist' => true]);
        DistributorCode::factory()->create([
            'created_by' => $otherStylist->id,
            'code' => 'OTHER-CODE',
        ]);

        $response = $this->getJson('/api/v1/stylist/codes/OTHER-CODE/stats');

        $response->assertStatus(404);
    }

    public function test_non_stylist_cannot_access_stylist_routes()
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/stylist/codes');

        $response->assertStatus(403);
    }
}
