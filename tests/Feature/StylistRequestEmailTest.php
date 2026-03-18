<?php

namespace Tests\Feature;

use App\Events\StylistRequestApproved;
use App\Events\StylistRequestRejected;
use App\Events\StylistRequestSubmitted;
use App\Listeners\SendNewStylistRequestAdminNotification;
use App\Listeners\SendStylistRequestStatusUpdate;
use App\Mail\Stylists\NewStylistRequestAdminMail;
use App\Mail\Stylists\StylistRequestStatusMail;
use App\Models\RequestStylist;
use App\Models\User;
use App\Services\StylistRequestService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StylistRequestEmailTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function submitting_a_stylist_request_dispatches_event_and_sends_admin_email()
    {
        Mail::fake();
        Event::fake([StylistRequestSubmitted::class]);

        $user = User::factory()->create(['email' => 'user@example.com']);

        /** @var StylistRequestService $service */
        $service = $this->app->make(StylistRequestService::class);

        $result = $service->submitRequest($user, [
            'saloon_name' => 'Test Salon',
            'saloon_city' => 'City',
            'saloon_address' => 'Address',
            'saloon_phone' => '123456',
        ]);

        $this->assertTrue($result['created']);

        Event::assertDispatched(StylistRequestSubmitted::class, function ($event) use ($user) {
            return $event->user->id === $user->id;
        });

        // Directly invoke listener to assert mail behaviour (event discovery is covered elsewhere)
        $request = RequestStylist::first();
        $listener = new SendNewStylistRequestAdminNotification();
        config(['tessa.admin_email' => 'admin@example.com']);

        $listener->handle(new StylistRequestSubmitted($request, $user));

        Mail::assertSent(NewStylistRequestAdminMail::class, function ($mail) use ($request, $user) {
            return $mail->requestStylist->id === $request->id && $mail->user->id === $user->id;
        });
    }

    /** @test */
    public function approving_a_stylist_request_dispatches_event_and_sends_status_email()
    {
        Mail::fake();
        Event::fake([StylistRequestApproved::class]);

        $user = User::factory()->create([
            'email' => 'user@example.com',
            'role' => User::ROLE_USER,
        ]);

        $request = RequestStylist::create([
            'user_id' => $user->id,
            'saloon_name' => 'Salon',
            'saloon_city' => 'City',
            'saloon_address' => 'Address',
            'saloon_phone' => '123456',
        ]);

        /** @var StylistRequestService $service */
        $service = $this->app->make(StylistRequestService::class);

        $result = $service->approve($request->id);

        $this->assertTrue($result['approved']);

        Event::assertDispatched(StylistRequestApproved::class, function ($event) use ($user, $request) {
            return $event->user->id === $user->id && $event->request->id === $request->id;
        });

        $listener = new SendStylistRequestStatusUpdate();
        $listener->handle(new StylistRequestApproved($request->fresh(), $user->fresh()));

        Mail::assertSent(StylistRequestStatusMail::class, function ($mail) use ($user) {
            return $mail->user->id === $user->id && $mail->statusLabel === 'Approved';
        });
    }

    /** @test */
    public function rejecting_a_stylist_request_dispatches_event_and_sends_status_email()
    {
        Mail::fake();
        Event::fake([StylistRequestRejected::class]);

        $user = User::factory()->create([
            'email' => 'user@example.com',
            'role' => User::ROLE_USER,
        ]);

        $request = RequestStylist::create([
            'user_id' => $user->id,
            'saloon_name' => 'Salon',
            'saloon_city' => 'City',
            'saloon_address' => 'Address',
            'saloon_phone' => '123456',
        ]);

        /** @var StylistRequestService $service */
        $service = $this->app->make(StylistRequestService::class);

        $service->reject($request->id, 'Not enough details');

        Event::assertDispatched(StylistRequestRejected::class, function ($event) use ($user, $request) {
            return $event->user->id === $user->id
                && $event->request->id === $request->id
                && $event->reason === 'Not enough details';
        });

        $listener = new SendStylistRequestStatusUpdate();
        $listener->handle(new StylistRequestRejected($request, $user, 'Not enough details'));

        Mail::assertSent(StylistRequestStatusMail::class, function ($mail) use ($user) {
            return $mail->user->id === $user->id && $mail->statusLabel === 'Rejected';
        });
    }
}

