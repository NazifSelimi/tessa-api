<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiLocaleTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function validation_errors_follow_the_shop_language()
    {
        $this->postJson('/api/v1/auth/login', [], ['Accept-Language' => 'mk'])
            ->assertStatus(422)
            ->assertJsonPath('errors.identifier.0', 'Полето телефон или е-пошта е задолжително.');

        $this->postJson('/api/v1/auth/login', [], ['Accept-Language' => 'shq'])
            ->assertJsonPath('errors.identifier.0', 'Fusha telefoni ose email-i është e detyrueshme.');

        $this->postJson('/api/v1/auth/login', [], ['Accept-Language' => 'mk-MK,mk;q=0.9'])
            ->assertJsonPath('errors.identifier.0', 'Полето телефон или е-пошта е задолжително.');
    }

    #[Test]
    public function albanian_product_descriptions_still_load()
    {
        $product = Product::factory()->create(['price' => 100, 'quantity' => 5]);
        $product->translations()->createMany([
            ['locale' => 'en', 'description' => 'English text'],
            ['locale' => 'shq', 'description' => 'Tekst shqip'],
        ]);

        $this->getJson("/api/v1/products/{$product->id}", ['Accept-Language' => 'shq'])
            ->assertOk()
            ->assertJsonPath('data.description', 'Tekst shqip');
    }
}
