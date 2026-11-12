<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\EmailSubscriptionController;
use App\Mail\AdminMessageMail;
use App\Mail\NewOrderAdminMail;
use App\Mail\OrderConfirmationMail;
use App\Models\EmailLog;
use App\Models\Order;
use App\Models\OrderInfo;
use App\Models\Setting;
use App\Models\User;
use App\Services\Mail\EmailService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailOutboxTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('features', '{}');
        Setting::put('features.plans', '{}');
        Setting::put('business.plan', 'premium');
        Setting::put('mail.test_recipient', '');
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function asAdmin()
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken);
    }

    private function guestOrder(): Order
    {
        $order = Order::factory()->create(['user_id' => null, 'total' => 1990, 'shipping' => 150]);
        OrderInfo::create([
            'order_id' => $order->id, 'first_name' => 'Mila', 'last_name' => 'Petrova', 'email' => 'mila@example.com',
            'phone' => '070 123 456', 'address' => 'Street 1', 'city' => 'Skopje', 'postal_code' => '1000', 'country' => 'MK',
        ]);

        return $order->load(['items.product', 'info', 'user']);
    }

    #[Test]
    public function the_shop_new_order_email_works_for_guest_orders_and_shows_denars()
    {
        // Production failed on every guest order ("first_name on null").
        $html = (new NewOrderAdminMail($this->guestOrder()))->render();

        $this->assertStringContainsString('Mila Petrova', $html);
        $this->assertStringContainsString('guest checkout', $html);
        $this->assertStringContainsString('1.990', $html);
        $this->assertStringNotContainsString('$', strip_tags($html));
    }

    #[Test]
    public function customer_emails_are_in_the_customers_language()
    {
        $mail = (new OrderConfirmationMail($this->guestOrder()))->locale('mk');

        $this->assertStringContainsString('Ви благодариме за нарачката', $mail->render());
        $this->assertSame('Ви благодариме за нарачката #' . $mail->order->id, app(EmailService::class)->subjectOf($mail));
    }

    #[Test]
    public function every_email_is_logged_as_sent()
    {
        Mail::fake();

        $log = app(EmailService::class)->send('ana@example.com', new AdminMessageMail('Hello', 'Hi', 'Body'), ['type' => 'test']);

        $this->assertSame(EmailLog::STATUS_SENT, $log->fresh()->status);
        $this->assertSame('log', $log->fresh()->delivery); // test server without a test address: log only
        Mail::assertSent(AdminMessageMail::class);
    }

    #[Test]
    public function a_failing_email_lands_in_the_not_sent_list_and_can_be_retried()
    {
        $log = app(EmailService::class)->send('ana@example.com', new ExplodingMail(), ['type' => 'test']);

        $this->assertSame(EmailLog::STATUS_FAILED, $log->fresh()->status);
        $this->assertStringContainsString('Mail server down', $log->fresh()->error);

        $this->asAdmin()->getJson('/api/v1/admin/emails/logs?status=not_sent')->assertOk()
            ->assertJsonFragment(['id' => (string) $log->id, 'status' => 'failed', 'canRetry' => true]);
        $this->asAdmin()->getJson('/api/v1/admin/emails/overview')->assertOk()->assertJsonPath('data.counts.failed', 1);

        $this->asAdmin()->postJson("/api/v1/admin/emails/logs/{$log->id}/retry")->assertOk();
        $this->assertSame(2, $log->fresh()->attempts);
    }

    #[Test]
    public function invalid_addresses_fail_without_trying()
    {
        $log = app(EmailService::class)->send('not-an-email', new AdminMessageMail('Hello', 'Hi', 'Body'));

        $this->assertSame(EmailLog::STATUS_FAILED, $log->status);
    }

    #[Test]
    public function an_admin_writes_to_a_group_and_unsubscribed_people_are_skipped()
    {
        Mail::fake();
        $stay = User::factory()->create(['role' => User::ROLE_STYLIST, 'email' => 'stay@example.com']);
        $left = User::factory()->create(['role' => User::ROLE_STYLIST, 'email' => 'left@example.com']);

        $this->get(EmailSubscriptionController::urlFor($left))->assertOk()->assertSee('unsubscribed');
        $this->assertNotNull($left->fresh()->email_opt_out_at);

        $payload = ['segment' => 'business', 'subject' => 'New stock', 'heading' => 'Hello {name}', 'body' => "Hi {name},\n\n**New** products are in."];
        $count = $this->asAdmin()->postJson('/api/v1/admin/emails/audience', $payload)->assertOk()->json('data.count');
        $this->asAdmin()->postJson('/api/v1/admin/emails/preview', $payload)->assertOk()->assertJsonPath('data.html', fn ($html) => str_contains($html, '<strong>New</strong>'));
        $this->asAdmin()->postJson('/api/v1/admin/emails/send', $payload)->assertCreated()->assertJsonPath('data.recipients', $count);

        $this->assertTrue(EmailLog::where('to_email', 'stay@example.com')->where('status', 'sent')->exists());
        $this->assertFalse(EmailLog::where('to_email', 'left@example.com')->exists());
        Mail::assertSent(AdminMessageMail::class, fn ($mail) => $mail->unsubscribeUrl !== null && $mail->hasTo('stay@example.com'));
    }

    #[Test]
    public function signed_links_are_required_to_unsubscribe()
    {
        $user = User::factory()->create();
        $this->get("/api/v1/email/unsubscribe/{$user->id}")->assertForbidden();
        $this->assertNull($user->fresh()->email_opt_out_at);
    }

    #[Test]
    public function writing_emails_can_be_switched_off()
    {
        Setting::put('features', json_encode(['admin_emails' => false]));
        $this->asAdmin()->getJson('/api/v1/admin/emails/logs')->assertNotFound();
        // Mailbox settings stay reachable so order emails keep working.
        $this->asAdmin()->getJson('/api/v1/admin/email-settings')->assertOk();
    }

    #[Test]
    public function mailbox_password_is_stored_encrypted_and_never_returned()
    {
        $this->asAdmin()->putJson('/api/v1/admin/email-settings', [
            'mode' => 'smtp', 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls',
            'username' => 'shop@gmail.com', 'password' => 'app-password-123',
        ])->assertOk()->assertJsonPath('data.hasPassword', true)->assertJsonMissing(['password' => 'app-password-123']);

        $this->assertNotSame('app-password-123', Setting::get('mail.password'));
    }
}

/** A mailable whose mail server "fails", for outbox failure tests. */
class ExplodingMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Will fail');
    }

    public function content(): Content
    {
        throw new \RuntimeException('Mail server down');
    }
}
