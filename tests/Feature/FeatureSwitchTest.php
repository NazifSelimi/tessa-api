<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FeatureSwitchTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('features', '{}');
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function asAdmin()
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken);
    }

    #[Test]
    public function the_shop_can_read_which_features_are_on()
    {
        $this->getJson('/api/v1/features')->assertOk()
            ->assertJsonPath('data.hair_quiz.enabled', true)
            ->assertJsonMissingPath('data.hair_quiz.plan')
            ->assertJsonMissingPath('data.order_delete.included');
    }

    #[Test]
    public function an_admin_switches_a_feature_off_and_its_routes_stop()
    {
        $this->asAdmin()->putJson('/api/v1/admin/features', ['features' => ['hair_quiz' => false, 'bundles' => false]])
            ->assertOk();

        $this->getJson('/api/v1/features')->assertJsonPath('data.hair_quiz.enabled', false);
        $this->postJson('/api/v1/recommendations', [])->assertNotFound()->assertJsonPath('errors.code', 'feature_off');
        // The shop always loads bundles; when off it just gets none.
        $this->getJson('/api/v1/bundles')->assertOk()->assertExactJson(['success' => true, 'data' => []]);
        $this->asAdmin()->getJson('/api/v1/admin/hair-quiz/products')->assertNotFound();
    }

    #[Test]
    public function there_are_no_plans_so_a_leftover_plan_setting_locks_nothing()
    {
        Setting::put('business.plan', 'basic');
        Setting::put('features.plans', json_encode(['admin_emails' => 'premium']));

        $this->getJson('/api/v1/features')->assertJsonPath('data.admin_emails.enabled', true);
        $this->asAdmin()->getJson('/api/v1/admin/emails/logs')->assertOk();
        $this->asAdmin()->getJson('/api/v1/admin/features')->assertOk()->assertJsonMissingPath('data.plan');
        $this->asAdmin()->putJson('/api/v1/admin/features', ['shop_plan' => 'basic'])->assertStatus(422);
    }

    #[Test]
    public function unknown_features_are_refused_and_customers_cannot_switch()
    {
        $this->asAdmin()->putJson('/api/v1/admin/features', ['features' => ['nope' => false]])->assertStatus(422);

        $customer = User::factory()->create(['role' => User::ROLE_USER]);
        $this->app['auth']->forgetGuards();
        $this->withToken($customer->createToken('t')->plainTextToken)
            ->putJson('/api/v1/admin/features', ['features' => ['hair_quiz' => false]])->assertForbidden();
    }

    #[Test]
    public function the_business_profile_is_editable_and_public()
    {
        $this->asAdmin()->putJson('/api/v1/admin/business', ['name' => 'Flora Shop', 'brand_color' => '#123456', 'phone' => '070 111 222'])
            ->assertOk()->assertJsonPath('data.name', 'Flora Shop');
        $this->getJson('/api/v1/business')->assertOk()
            ->assertJsonPath('data.name', 'Flora Shop')
            ->assertJsonPath('data.brand_color', '#123456');
        $this->asAdmin()->putJson('/api/v1/admin/business', ['brand_color' => 'red'])->assertStatus(422);
    }

    /** #22: with "Distributor codes" off, the stylist code pages stop too (like the admin ones). */
    #[Test]
    public function distributor_codes_off_closes_the_stylist_code_pages()
    {
        Setting::put('features', json_encode(['distributors' => false]));
        $this->app->forgetInstance(\App\Services\FeatureService::class);
        \Illuminate\Support\Facades\Cache::flush();
        $stylist = User::factory()->create(['role' => User::ROLE_STYLIST]);
        $this->app['auth']->forgetGuards();

        $this->withToken($stylist->createToken('t')->plainTextToken)->getJson('/api/v1/stylist/codes')->assertNotFound()->assertJsonPath('errors.code', 'feature_off');
        $this->withToken($stylist->createToken('t')->plainTextToken)->postJson('/api/v1/stylist/codes/generate')->assertNotFound();
    }

    /** #22: with "Referral codes & store credit" off, checkout never spends store credit. */
    #[Test]
    public function referrals_off_means_no_store_credit_is_spent()
    {
        $stylist = User::factory()->create(['role' => User::ROLE_STYLIST]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        app(\App\Services\ReferralService::class)->adjust($stylist, 500, 'Test credit', $admin);
        Setting::put('features', json_encode(['referrals' => false]));
        $this->app->forgetInstance(\App\Services\FeatureService::class);
        \Illuminate\Support\Facades\Cache::flush();
        $product = \App\Models\Product::factory()->create(['price' => 1000, 'stylist_price' => 800, 'quantity' => 10]);
        $this->app['auth']->forgetGuards();

        $response = $this->withToken($stylist->createToken('t')->plainTextToken)->postJson('/api/v1/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'use_store_credit' => true,
            'shipping_address' => ['firstName' => 'S', 'lastName' => 'Alon', 'email' => 's@example.com', 'phone' => '070555666',
                'address' => 'S 1', 'city' => 'Skopje', 'zip' => '1000'],
        ])->assertStatus(201);

        $this->assertEquals(0, (float) $response->json('data.storeCreditUsed'));
        $this->assertEqualsWithDelta(500, app(\App\Services\ReferralService::class)->balance($stylist), 0.01);
    }

}
