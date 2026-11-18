<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminBundleImageTest extends TestCase
{
    use DatabaseTransactions;

    /** A real 1×1 PNG, so the test needs no GD extension. */
    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg=='));
    }

    private function bundle(): Bundle
    {
        return Bundle::create(['name' => 'Pair', 'audience' => 'all', 'promotion_type' => 'percentage', 'discount_percentage' => 10, 'is_active' => true]);
    }

    #[Test]
    public function admins_save_replace_and_remove_a_bundle_picture()
    {
        Storage::fake('public');
        $bundle = $this->bundle();
        $token = User::factory()->create(['role' => User::ROLE_ADMIN])->createToken('t')->plainTextToken;

        $first = $this->withToken($token)->post("/api/v1/admin/bundles/{$bundle->id}/image", [
            'image' => $this->png('post.png'),
        ], ['Accept' => 'application/json'])->assertOk();
        $firstPath = $bundle->fresh()->image_path;
        Storage::disk('public')->assertExists($firstPath);
        $this->assertStringContainsString('storage/bundles/', $first->json('data.image'));

        $this->withToken($token)->post("/api/v1/admin/bundles/{$bundle->id}/image", [
            'image' => $this->png('again.png'),
        ], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing($firstPath);
        $secondPath = $bundle->fresh()->image_path;
        Storage::disk('public')->assertExists($secondPath);

        $this->withToken($token)->deleteJson("/api/v1/admin/bundles/{$bundle->id}/image")->assertOk()->assertJsonPath('data.image', null);
        Storage::disk('public')->assertMissing($secondPath);
        $this->assertNull($bundle->fresh()->image_path);
    }

    #[Test]
    public function only_admins_upload_bundle_pictures_and_only_images()
    {
        Storage::fake('public');
        $bundle = $this->bundle();
        $url = "/api/v1/admin/bundles/{$bundle->id}/image";

        $this->post($url, ['image' => $this->png('a.png')], ['Accept' => 'application/json'])->assertStatus(401);

        $customer = User::factory()->create()->createToken('t')->plainTextToken;
        $this->withToken($customer)->post($url, ['image' => $this->png('a.png')], ['Accept' => 'application/json'])->assertStatus(403);

        $this->app['auth']->forgetGuards(); // the guard remembers the customer otherwise
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN])->createToken('t')->plainTextToken;
        $this->withToken($admin)->post($url, ['image' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertNull($bundle->fresh()->image_path);
    }
}
