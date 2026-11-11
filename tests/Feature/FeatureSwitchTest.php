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
        Setting::put('features.plans', '{}');
        Setting::put('business.plan', 'premium');
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
            ->assertJsonPath('data.hair_quiz.plan', 'basic')
            ->assertJsonPath('data.order_delete.included', true);
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
    public function a_basic_plan_locks_pro_and_premium_features()
    {
        $this->asAdmin()->putJson('/api/v1/admin/features', ['shop_plan' => 'basic'])->assertOk()
            ->assertJsonPath('data.plan', 'basic');

        $this->getJson('/api/v1/features')
            ->assertJsonPath('data.admin_emails.included', false)
            ->assertJsonPath('data.admin_emails.enabled', true) // still switched on, just not in the plan
            ->assertJsonPath('data.hair_quiz.included', true);
        $this->asAdmin()->getJson('/api/v1/admin/emails/logs')->assertNotFound();

        // Moving one feature down to Basic unlocks it without changing the plan.
        $this->asAdmin()->putJson('/api/v1/admin/features', ['plans' => ['admin_emails' => 'basic']])->assertOk();
        $this->asAdmin()->getJson('/api/v1/admin/emails/logs')->assertOk();
        $this->asAdmin()->putJson('/api/v1/admin/features', ['shop_plan' => 'gold'])->assertStatus(422);
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
}
