<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\EmailSubscriptionController;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Mail\AdminMessageMail;
use App\Mail\NewOrderAdminMail;
use App\Mail\OrderConfirmationMail;
use App\Mail\OrderStatusUpdateMail;
use App\Mail\PasswordResetMail;
use App\Mail\Stylists\StylistRequestStatusMail;
use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\BusinessProfile;
use App\Services\Mail\EmailService;
use App\Services\Mail\MailSettings;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Admin email centre: mailbox settings, outbox (with the "not sent" list and
 * retry), writing emails to one person or a group, and previews of the
 * automatic emails.
 */
class AdminEmailController extends Controller
{
    private const SEGMENTS = ['customers', 'business', 'all', 'recent_buyers', 'inactive_buyers', 'users', 'emails'];

    private const MAX_RECIPIENTS = 5000;

    public function __construct(
        private readonly MailSettings $settings,
        private readonly EmailService $emails,
        private readonly BusinessProfile $business,
    ) {}

    /* ------------------------------------------------------------- settings */

    public function settings()
    {
        return ApiResponse::ok($this->settings->forAdmin());
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'mode' => ['sometimes', Rule::in(['env', 'resend', 'smtp', 'off'])],
            'resendKey' => ['nullable', 'string', 'starts_with:re_', 'max:200'],
            'clearResendKey' => ['sometimes', 'boolean'],
            'dailyLimit' => ['nullable', 'integer', 'between:0,100000'],
            'host' => ['nullable', 'string', 'max:190'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:500'],
            'clearPassword' => ['sometimes', 'boolean'],
            'fromAddress' => ['nullable', 'email', 'max:190'],
            'fromName' => ['nullable', 'string', 'max:120'],
            'replyTo' => ['nullable', 'email', 'max:190'],
            'adminRecipients' => ['nullable', 'string', 'max:1000'],
            'testRecipient' => ['nullable', 'email', 'max:190'],
            'autoEmails' => ['sometimes', 'array'],
            'autoEmails.*' => ['boolean'],
        ]);
        if (($data['mode'] ?? null) === 'smtp' && empty($data['host']) && ! $this->settings->raw('host')) {
            return ApiResponse::error('Enter the mail server (host) of your mailbox.', 422);
        }
        if (($data['mode'] ?? null) === 'resend' && empty($data['resendKey']) && ! $this->settings->resendKey()) {
            return ApiResponse::error('Paste your Resend API key (Resend → API Keys; the free plan is enough).', 422);
        }
        $this->settings->update($data);

        return ApiResponse::ok($this->settings->forAdmin(), 200, null, 'Email settings saved');
    }

    /** SPF / DKIM / DMARC of the sending domain, so emails don't land in spam. */
    public function deliverability(Request $request, \App\Services\Mail\DeliverabilityCheck $check)
    {
        $from = (string) ($request->query('from') ?: $this->settings->raw('from_address', '') ?: config('mail.from.address'));
        $domain = str_contains($from, '@') ? substr(strrchr($from, '@'), 1) : $from;
        if (! $domain || ! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
            return ApiResponse::error('Enter a "Send as" address first.', 422);
        }

        return ApiResponse::ok($check->run($domain) + ['from' => $from]);
    }

    /** Sends one email straight away (not queued) and reports the real error, if any. */
    public function test(Request $request)
    {
        $data = $request->validate(['to' => ['required', 'email']]);
        $shop = $this->business->get('name');
        $mail = new AdminMessageMail(
            subjectLine: "Test email from {$shop}",
            heading: 'Your email works',
            body: "This is a test from the {$shop} admin.\n\nIf you can read this, customers will receive order emails and the emails you write.",
        );

        $log = EmailLog::create([
            'type' => 'test',
            'to_email' => $data['to'],
            'subject' => "Test email from {$shop}",
            'status' => EmailLog::STATUS_QUEUED,
            'payload' => serialize($mail),
            'created_by' => $request->user()?->id,
            'attempts' => 1,
        ]);

        try {
            $this->settings->apply();
            $delivery = $this->settings->deliveryMode();
            Mail::to($data['to'])->sendNow($mail);
            $log->update([
                'status' => EmailLog::STATUS_SENT,
                'delivery' => $delivery,
                'redirected_to' => $delivery === 'redirected' ? $this->settings->testRecipient() : null,
                'sent_at' => now(),
            ]);

            return ApiResponse::ok([
                'sent' => true,
                'delivery' => $delivery,
                'note' => $this->settings->describe(),
            ], 200, null, $delivery === 'log' ? 'Written to the log only (sending is off)' : 'Test email sent');
        } catch (Throwable $e) {
            $log->update(['status' => EmailLog::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000)]);

            return ApiResponse::error('The mail server refused: ' . self::explain($e->getMessage()), 422, ['raw' => $e->getMessage()]);
        }
    }

    /** Turns common SMTP errors into advice an owner can act on. */
    private static function explain(string $error): string
    {
        $lower = strtolower($error);

        return match (true) {
            str_contains($lower, '535') || str_contains($lower, 'authentication') || str_contains($lower, 'username and password')
                => 'wrong username or password. For Gmail use an App Password, not your normal password.',
            str_contains($lower, 'connection refused') || str_contains($lower, 'timed out') || str_contains($lower, 'could not be established')
                => 'could not reach the mail server. Check the server name and port (587 with TLS or 465 with SSL).',
            str_contains($lower, 'certificate') || str_contains($lower, 'ssl')
                => 'secure connection failed. Try the other encryption (TLS on 587 or SSL on 465).',
            str_contains($lower, 'sender') || str_contains($lower, '553') || str_contains($lower, '550')
                => 'the mailbox may not send as this "From" address. Use the same address as the username.',
            default => $error,
        };
    }

    /* ------------------------------------------------------------- outbox */

    public function overview()
    {
        $counts = EmailLog::query()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return ApiResponse::ok([
            'counts' => [
                'sent' => (int) ($counts[EmailLog::STATUS_SENT] ?? 0),
                'queued' => (int) ($counts[EmailLog::STATUS_QUEUED] ?? 0),
                'retrying' => (int) ($counts[EmailLog::STATUS_RETRYING] ?? 0),
                'failed' => (int) ($counts[EmailLog::STATUS_FAILED] ?? 0),
            ],
            'notSent' => EmailLog::whereIn('status', EmailLog::NOT_SENT)->count(),
            'legacyFailed' => $this->legacyFailedJobs()->count(),
            'delivery' => $this->settings->deliveryMode(),
            'deliveryNote' => $this->settings->describe(),
            'sentLast7Days' => EmailLog::where('status', EmailLog::STATUS_SENT)->where('sent_at', '>=', now()->subDays(7))->count(),
            'optedOut' => User::whereNotNull('email_opt_out_at')->count(),
            'sentToday' => $this->sentSince(now()->startOfDay()),
            'sentThisMonth' => $this->sentSince(now()->startOfMonth()),
            'dailyLimit' => $this->settings->dailyLimit(),
            'monthlyLimit' => $this->settings->raw('mode', 'env') === 'resend' ? MailSettings::RESEND_MONTHLY_LIMIT : null,
        ]);
    }

    private function sentSince(\DateTimeInterface $since): int
    {
        return EmailLog::where('status', EmailLog::STATUS_SENT)->where('delivery', 'smtp')->where('sent_at', '>=', $since)->count();
    }

    public function logs(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['sent', 'queued', 'retrying', 'failed', 'not_sent'])],
            'type' => ['nullable', 'string', 'max:60'],
            'search' => ['nullable', 'string', 'max:190'],
            'campaign_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = EmailLog::query()->with('campaign:id,subject')->latest('id');
        match ($data['status'] ?? null) {
            'not_sent' => $query->whereIn('status', EmailLog::NOT_SENT),
            null => null,
            default => $query->where('status', $data['status']),
        };
        if (! empty($data['type'])) {
            $query->where('type', $data['type']);
        }
        if (! empty($data['campaign_id'])) {
            $query->where('campaign_id', $data['campaign_id']);
        }
        if (! empty($data['search'])) {
            $term = '%' . $data['search'] . '%';
            $query->where(fn (Builder $q) => $q->where('to_email', 'like', $term)
                ->orWhere('to_name', 'like', $term)
                ->orWhere('subject', 'like', $term)
                ->orWhere('order_id', $data['search']));
        }
        $page = $query->paginate($data['per_page'] ?? 25);

        return ApiResponse::ok(
            collect($page->items())->map(fn (EmailLog $log) => $this->logData($log))->all(),
            200,
            ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        );
    }

    public function showLog(EmailLog $log)
    {
        return ApiResponse::ok($this->logData($log) + ['html' => $this->emails->renderLog($log)]);
    }

    public function retry(EmailLog $log)
    {
        if ($log->status === EmailLog::STATUS_SENT) {
            return ApiResponse::error('This email was already sent.', 422);
        }
        if (! $log->payload) {
            return ApiResponse::error('This email has no saved content to send again.', 422);
        }

        return ApiResponse::ok($this->logData($this->emails->retry($log)), 200, null, 'Email queued again');
    }

    public function retryAll()
    {
        $count = 0;
        EmailLog::query()->where('status', EmailLog::STATUS_FAILED)->whereNotNull('payload')
            ->orderBy('id')->chunkById(200, function (Collection $logs) use (&$count) {
                foreach ($logs as $log) {
                    $this->emails->retry($log);
                    $count++;
                }
            });

        return ApiResponse::ok(['queued' => $count], 200, null, "$count email(s) queued again");
    }

    public function destroyLog(EmailLog $log)
    {
        $log->delete();

        return ApiResponse::ok(null, 200, null, 'Removed from the outbox');
    }

    /* ------------------------------------------------- older failed jobs */

    private function legacyFailedJobs(): Collection
    {
        return DB::table('failed_jobs')->orderBy('failed_at')->get()
            ->filter(fn ($job) => str_contains((string) $job->payload, 'SendQueuedMailable')
                || str_contains((string) $job->payload, 'App\\\\Listeners\\\\Send'));
    }

    /**
     * Moves emails that failed before the outbox existed into it, so they can
     * be read and retried like any other. Jobs that can't be read stay put.
     */
    public function importLegacy()
    {
        $imported = 0;
        $skipped = [];
        foreach ($this->legacyFailedJobs() as $job) {
            try {
                $payload = json_decode((string) $job->payload, true);
                $command = unserialize($payload['data']['command'] ?? '');
                $mailable = $command->mailable ?? null;
                if (! $mailable instanceof Mailable) {
                    $skipped[] = ['id' => $job->uuid, 'reason' => 'Not an email (' . ($payload['displayName'] ?? 'job') . ')'];
                    continue;
                }
                $recipients = collect($mailable->to)->pluck('address')->filter()->values();
                if ($recipients->isEmpty()) {
                    $skipped[] = ['id' => $job->uuid, 'reason' => 'No recipient'];
                    continue;
                }
                $mailable->to = [];
                foreach ($recipients as $address) {
                    EmailLog::query()->forceCreate([
                        'type' => \Illuminate\Support\Str::kebab(class_basename($mailable)),
                        'to_email' => $address,
                        'subject' => $this->emails->subjectOf($mailable),
                        'status' => EmailLog::STATUS_FAILED,
                        'error' => 'Before the outbox: ' . mb_substr(strtok((string) $job->exception, "\n"), 0, 1500),
                        'attempts' => 3,
                        'payload' => serialize($mailable),
                        'order_id' => $mailable->order->id ?? null,
                        'created_at' => $job->failed_at,
                        'updated_at' => $job->failed_at,
                    ]);
                    $imported++;
                }
                DB::table('failed_jobs')->where('id', $job->id)->delete();
            } catch (Throwable $e) {
                $skipped[] = ['id' => $job->uuid, 'reason' => mb_substr($e->getMessage(), 0, 200)];
            }
        }

        return ApiResponse::ok(['imported' => $imported, 'skipped' => $skipped], 200, null, "$imported older email(s) moved into the outbox");
    }

    /* ------------------------------------------------------------- compose */

    private function composeRules(): array
    {
        return [
            'segment' => ['required', Rule::in(self::SEGMENTS)],
            'days' => ['nullable', 'integer', 'between:1,3650'],
            'user_ids' => ['nullable', 'array', 'max:' . self::MAX_RECIPIENTS],
            'user_ids.*' => ['integer'],
            'emails' => ['nullable', 'string', 'max:20000'],
            'subject' => ['required', 'string', 'max:190'],
            'heading' => ['nullable', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:10000'],
            'button_text' => ['nullable', 'string', 'max:60'],
            'button_url' => ['nullable', 'url', 'max:500'],
            'product_ids' => ['nullable', 'array', 'max:8'],
            'product_ids.*' => ['integer'],
        ];
    }

    /** People a segment reaches: users skip admins, unsubscribed and blank emails; manual addresses are kept as typed. */
    private function recipients(array $data): Collection
    {
        $segment = $data['segment'];
        if ($segment === 'emails') {
            $typed = collect(preg_split('/[\s,;]+/', (string) ($data['emails'] ?? '')))
                ->map(fn ($email) => strtolower(trim($email)))
                ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
                ->unique()->values();
            $users = User::whereIn(DB::raw('LOWER(email)'), $typed)->get()->keyBy(fn (User $user) => strtolower($user->email));

            return $typed->map(fn ($email) => ['email' => $email, 'user' => $users[$email] ?? null]);
        }

        $days = (int) ($data['days'] ?? 90);
        $query = User::query()
            ->whereNotNull('email')->where('email', '!=', '')
            ->where('role', '!=', User::ROLE_ADMIN);

        // Group emails respect "unsubscribe"; an email to chosen people is a direct message.
        if ($segment !== 'users') {
            $query->whereNull('email_opt_out_at');
        }

        match ($segment) {
            'customers' => $query->where('role', User::ROLE_USER),
            'business' => $query->where('role', User::ROLE_STYLIST),
            'recent_buyers' => $query->whereHas('orders', fn ($orders) => $orders->where('created_at', '>=', now()->subDays($days))),
            'inactive_buyers' => $query->whereHas('orders')
                ->whereDoesntHave('orders', fn ($orders) => $orders->where('created_at', '>=', now()->subDays($days))),
            'users' => $query->whereIn('id', $data['user_ids'] ?? []),
            default => null,
        };

        return $query->limit(self::MAX_RECIPIENTS)->get()
            ->unique(fn (User $user) => strtolower($user->email))
            ->map(fn (User $user) => ['email' => $user->email, 'user' => $user])->values();
    }

    private function segmentLabel(array $data): string
    {
        $days = (int) ($data['days'] ?? 90);

        return match ($data['segment']) {
            'customers' => 'All customers',
            'business' => 'All business accounts',
            'all' => 'Customers and business accounts',
            'recent_buyers' => "Bought in the last $days days",
            'inactive_buyers' => "Bought before, nothing in the last $days days",
            'users' => 'Chosen people',
            default => 'Typed addresses',
        };
    }

    public function audience(Request $request)
    {
        $data = $request->validate(array_intersect_key($this->composeRules(), array_flip(['segment', 'days', 'user_ids', 'user_ids.*', 'emails'])));
        $recipients = $this->recipients($data);

        return ApiResponse::ok([
            'count' => $recipients->count(),
            'label' => $this->segmentLabel($data),
            'sample' => $recipients->take(5)->map(fn ($r) => $r['user']
                ? trim($r['user']->first_name . ' ' . $r['user']->last_name) . ' <' . $r['email'] . '>'
                : $r['email'])->values(),
            'limit' => self::MAX_RECIPIENTS,
            'dailyLimit' => $this->settings->dailyLimit(),
            'leftToday' => max(0, $this->settings->dailyLimit() - $this->sentSince(now()->startOfDay())),
        ]);
    }

    /** @return list<array{name: string, price: string|null, image: string|null, url: string}> */
    private function productCards(array $ids, bool $businessPrices): array
    {
        if ($ids === []) {
            return [];
        }
        $site = rtrim($this->business->get('website'), '/');
        $products = Product::with(['images', 'brand', 'category'])->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $products[$id] ?? null)->filter()->map(function (Product $product) use ($site, $businessPrices) {
            $resource = (new ProductResource($product))->resolve(request());
            $price = (float) ($businessPrices && (float) $product->stylist_price > 0 ? $product->stylist_price : $product->price);

            return [
                'name' => $product->name,
                'price' => $price > 0 ? number_format(round($price), 0, ',', '.') . ' MKD' : null,
                'image' => $resource['image'] ?? null,
                'url' => $site . '/product/' . $product->id,
            ];
        })->values()->all();
    }

    private function buildMail(array $data, ?User $user, bool $withUnsubscribe): AdminMessageMail
    {
        return new AdminMessageMail(
            subjectLine: $data['subject'],
            heading: (string) ($data['heading'] ?? ''),
            body: $data['body'],
            buttonText: $data['button_text'] ?? null,
            buttonUrl: $data['button_url'] ?? null,
            products: $this->productCards($data['product_ids'] ?? [], $data['segment'] === 'business' || $user?->isStylist()),
            recipientName: $user?->first_name,
            unsubscribeUrl: $withUnsubscribe && $user ? EmailSubscriptionController::urlFor($user) : null,
        );
    }

    public function preview(Request $request)
    {
        $rules = $this->composeRules();
        $rules['subject'] = ['nullable', 'string', 'max:190'];
        $rules['body'] = ['nullable', 'string', 'max:10000'];
        $data = $request->validate($rules) + ['subject' => '', 'body' => ''];
        $sample = $this->recipients($data)->first();
        $mail = $this->buildMail($data, $sample['user'] ?? null, $data['segment'] !== 'users' && $data['segment'] !== 'emails');

        return ApiResponse::ok(['html' => $mail->render(), 'sampleRecipient' => $sample['email'] ?? null]);
    }

    public function send(Request $request)
    {
        $data = $request->validate($this->composeRules());
        $recipients = $this->recipients($data);
        if ($recipients->isEmpty()) {
            return ApiResponse::error('Nobody to send to: the group is empty or everyone unsubscribed.', 422);
        }

        $isGroup = ! in_array($data['segment'], ['users', 'emails'], true) || $recipients->count() > 1;
        $campaign = EmailCampaign::create([
            'subject' => $data['subject'],
            'audience' => $data['segment'],
            'audience_label' => $this->segmentLabel($data),
            'content' => array_intersect_key($data, array_flip(['heading', 'body', 'button_text', 'button_url', 'product_ids', 'days'])),
            'recipients_count' => $recipients->count(),
            'created_by' => $request->user()?->id,
        ]);

        // Stay inside the mailbox's daily limit: what doesn't fit today goes out on the next days.
        $limit = max(1, $this->settings->dailyLimit());
        $leftToday = max(0, $limit - $this->sentSince(now()->startOfDay()));
        foreach ($recipients->values() as $index => $recipient) {
            /** @var User|null $user */
            $user = $recipient['user'];
            $sendAt = null;
            if ($index >= $leftToday) {
                $day = intdiv($index - $leftToday, $limit) + 1;
                $sendAt = now()->addDays($day)->startOfDay()->addHours(9)->addSeconds(($index - $leftToday) % $limit * 20);
            } elseif ($index > 0) {
                $sendAt = now()->addSeconds($index * 2); // a little spacing so the mailbox isn't flooded
            }
            $this->emails->send($recipient['email'], $this->buildMail($data, $user, $isGroup), [
                'type' => 'admin-message',
                'user_id' => $user?->id,
                'name' => $user ? trim($user->first_name . ' ' . $user->last_name) : null,
                'campaign_id' => $campaign->id,
                'created_by' => $request->user()?->id,
            ], $sendAt);
        }
        $spreadDays = $recipients->count() > $leftToday ? (int) ceil(($recipients->count() - $leftToday) / $limit) : 0;

        return ApiResponse::ok([
            'campaignId' => $campaign->id,
            'recipients' => $recipients->count(),
            'spreadDays' => $spreadDays,
            'delivery' => $this->settings->deliveryMode(),
            'note' => $this->settings->describe(),
        ], 201, null, 'Sending to ' . $recipients->count() . ' recipient(s)');
    }

    public function campaigns()
    {
        $campaigns = EmailCampaign::query()->with('author:id,first_name,last_name')->latest('id')->limit(50)->get();
        $stats = EmailLog::query()->whereIn('campaign_id', $campaigns->pluck('id'))
            ->selectRaw('campaign_id, status, COUNT(*) as c')->groupBy('campaign_id', 'status')->get()
            ->groupBy('campaign_id');

        return ApiResponse::ok($campaigns->map(function (EmailCampaign $campaign) use ($stats) {
            $byStatus = ($stats[$campaign->id] ?? collect())->pluck('c', 'status');

            return [
                'id' => (string) $campaign->id,
                'subject' => $campaign->subject,
                'audience' => $campaign->audience_label ?: $campaign->audience,
                'recipients' => $campaign->recipients_count,
                'sent' => (int) ($byStatus['sent'] ?? 0),
                'failed' => (int) ($byStatus['failed'] ?? 0),
                'pending' => (int) (($byStatus['queued'] ?? 0) + ($byStatus['retrying'] ?? 0)),
                'createdBy' => $campaign->author ? trim($campaign->author->first_name . ' ' . $campaign->author->last_name) : null,
                'createdAt' => $campaign->created_at?->toISOString(),
            ];
        }));
    }

    /* --------------------------------------------- automatic email previews */

    public function templates()
    {
        $list = [];
        foreach (MailSettings::AUTO_EMAILS as $key => $meta) {
            $list[] = ['key' => $key, 'label' => $meta['label'], 'enabled' => $this->settings->autoEnabled($key), 'switchable' => true];
        }
        $list[] = ['key' => 'password_reset', 'label' => 'Password reset link to the customer', 'enabled' => true, 'switchable' => false];

        return ApiResponse::ok($list);
    }

    /** The automatic email as it looks today, filled with the latest order / business account. */
    public function templatePreview(Request $request, string $key)
    {
        $locale = in_array($request->query('locale'), ['mk', 'sq', 'en'], true) ? $request->query('locale') : 'mk';
        $order = Order::with(['items.product', 'info', 'user'])->latest('id')->first();
        $stylistRequest = \App\Models\RequestStylist::query()->whereHas('user')->with('user')->latest('id')->first();
        $stylist = $stylistRequest?->user;

        $mail = match ($key) {
            'order_confirmation' => $order ? new OrderConfirmationMail($order) : null,
            'order_status' => $order ? new OrderStatusUpdateMail($order, 'shipped') : null,
            'admin_new_order' => $order ? new NewOrderAdminMail($order) : null,
            'admin_stylist_request' => $stylistRequest && $stylist ? new \App\Mail\Stylists\NewStylistRequestAdminMail($stylistRequest, $stylist) : null,
            'stylist_status' => $stylist ? new StylistRequestStatusMail($stylist, 'approved', $stylistRequest) : null,
            'password_reset' => new PasswordResetMail($request->user(), 'preview-token'),
            default => null,
        };
        if (! $mail) {
            return ApiResponse::error('Nothing to preview yet (no order or business account).', 404);
        }
        if (! str_starts_with($key, 'admin_')) {
            $mail->locale($locale);
        }

        try {
            return ApiResponse::ok(['subject' => $this->emails->subjectOf($mail), 'html' => $mail->render()]);
        } catch (Throwable $e) {
            return ApiResponse::error('Preview failed: ' . $e->getMessage(), 500);
        }
    }

    /* ------------------------------------------------------------- shared */

    private function logData(EmailLog $log): array
    {
        return [
            'id' => (string) $log->id,
            'type' => $log->type,
            'to' => $log->to_email,
            'toName' => $log->to_name,
            'subject' => $log->subject,
            'status' => $log->status,
            'delivery' => $log->delivery,
            'redirectedTo' => $log->redirected_to,
            'error' => $log->error,
            'attempts' => $log->attempts,
            'orderId' => $log->order_id ? (string) $log->order_id : null,
            'userId' => $log->user_id ? (string) $log->user_id : null,
            'campaignId' => $log->campaign_id ? (string) $log->campaign_id : null,
            'campaign' => $log->campaign?->subject,
            'canRetry' => $log->status !== EmailLog::STATUS_SENT && (bool) $log->payload,
            'sentAt' => $log->sent_at?->toISOString(),
            'createdAt' => $log->created_at?->toISOString(),
        ];
    }
}
