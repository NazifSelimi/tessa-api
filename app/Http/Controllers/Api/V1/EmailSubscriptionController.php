<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/** One-click unsubscribe from emails the shop writes (links are signed, no login). */
class EmailSubscriptionController extends Controller
{
    public function unsubscribe(Request $request, User $user)
    {
        $user->forceFill(['email_opt_out_at' => $user->email_opt_out_at ?? now()])->save();

        return response()->view('emails.unsubscribed', [
            'subscribed' => false,
            'resubscribeUrl' => URL::signedRoute('email.resubscribe', ['user' => $user->id]),
        ]);
    }

    public function resubscribe(Request $request, User $user)
    {
        $user->forceFill(['email_opt_out_at' => null])->save();

        return response()->view('emails.unsubscribed', ['subscribed' => true, 'resubscribeUrl' => null]);
    }

    /** RFC 8058 one-click unsubscribe (Gmail / Apple Mail "Unsubscribe" button). */
    public function oneClick(Request $request, User $user)
    {
        $user->forceFill(['email_opt_out_at' => $user->email_opt_out_at ?? now()])->save();

        return response()->noContent();
    }

    public static function urlFor(User $user): string
    {
        return URL::signedRoute('email.unsubscribe', ['user' => $user->id]);
    }
}
