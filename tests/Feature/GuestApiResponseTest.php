<?php

namespace Tests\Feature;

use Tests\TestCase;

class GuestApiResponseTest extends TestCase
{
    public function test_guest_without_json_accept_header_gets_401_json_not_500(): void
    {
        $response = $this->get('/api/v1/products/quick-order');

        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    public function test_guest_with_json_accept_header_gets_401(): void
    {
        $this->getJson('/api/v1/products/quick-order')->assertStatus(401);
    }
}
