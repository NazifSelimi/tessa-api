<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Messaging\MessagingAudience;
use App\Services\Messaging\MessagingSettings;
use App\Services\Messaging\PhoneNumber;
use App\Services\Messaging\WhatsAppSender;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /admin/messages: Viber & WhatsApp message templates, the "send to a list"
 * list, and the optional automatic WhatsApp (Cloud API, off by default).
 */
class AdminMessagingController extends Controller
{
    public function __construct(
        private readonly MessagingSettings $settings,
        private readonly MessagingAudience $audience,
        private readonly WhatsAppSender $whatsapp,
    ) {}

    public function templates()
    {
        return ApiResponse::ok($this->settings->templates());
    }

    public function saveTemplates(Request $request)
    {
        $data = $request->validate([
            'templates' => ['sometimes', 'array', 'max:60'],
            'templates.*.key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'templates.*.label' => ['nullable', 'string', 'max:80'],
            'templates.*.mk' => ['nullable', 'string', 'max:1500'],
            'templates.*.sq' => ['nullable', 'string', 'max:1500'],
            'templates.*.en' => ['nullable', 'string', 'max:1500'],
            'templates.*.custom' => ['sometimes', 'boolean'],
            'shop' => ['nullable', 'string', 'max:80'],
            'link' => ['nullable', 'string', 'max:300'],
        ]);

        return ApiResponse::ok($this->settings->saveTemplates($data), 200, null, 'Templates saved');
    }

    public function audience(Request $request)
    {
        $data = $request->validate([
            'segment' => ['required', Rule::in(MessagingAudience::SEGMENTS)],
            'days' => ['nullable', 'integer', 'between:1,3650'],
            'numbers' => ['nullable', 'string', 'max:60000'],
        ]);
        $rows = $this->audience->build($data['segment'], (int) ($data['days'] ?? 90), (string) ($data['numbers'] ?? ''));

        return ApiResponse::ok($rows, 200, ['count' => count($rows), 'limit' => MessagingAudience::MAX]);
    }

    public function whatsapp()
    {
        return ApiResponse::ok($this->settings->whatsappForAdmin());
    }

    public function saveWhatsApp(Request $request)
    {
        $templateRules = [];
        foreach (array_keys(MessagingSettings::STATUSES) as $status) {
            $templateRules["statusTemplates.$status.name"] = ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9_]*$/'];
            $templateRules["statusTemplates.$status.language"] = ['nullable', 'string', 'max:10'];
            $templateRules["statusTemplates.$status.params"] = ['nullable', 'integer', 'between:0,3'];
        }
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'phoneNumberId' => ['nullable', 'string', 'max:40', 'regex:/^\d*$/'],
            'businessAccountId' => ['nullable', 'string', 'max:40', 'regex:/^\d*$/'],
            'apiVersion' => ['nullable', 'string', 'max:10', 'regex:/^(v\d+\.\d+)?$/'],
            'accessToken' => ['nullable', 'string', 'max:1000'],
            'clearToken' => ['sometimes', 'boolean'],
            'testNumber' => ['nullable', 'string', 'max:30'],
            'statusTemplates' => ['sometimes', 'array'],
        ] + $templateRules);

        $saved = $this->settings->saveWhatsApp($data);
        if (! empty($data['enabled']) && ! $saved['enabled']) {
            return ApiResponse::error('Save the phone number ID and access token before switching automatic messages on.', 422);
        }

        return ApiResponse::ok($saved, 200, null, 'WhatsApp settings saved');
    }

    /** Sends one real template message (Meta may bill it) to the number typed by the admin. */
    public function testWhatsApp(Request $request)
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:30'],
            'template' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9_]*$/'],
            'language' => ['nullable', 'string', 'max:10'],
        ]);
        $to = PhoneNumber::normalize($data['to']);
        if (! $to) {
            return ApiResponse::error('That phone number does not look right.', 422);
        }
        // Meta's ready-made "hello_world" template works on every new account (English only).
        $template = $data['template'] ?: 'hello_world';
        $language = $data['language'] ?: ($template === 'hello_world' ? 'en_US' : 'mk');

        $result = $this->whatsapp->test($to, $template, $language);
        if (! $result['ok']) {
            return ApiResponse::error((string) $result['error'], 422);
        }

        return ApiResponse::ok($result, 200, null, 'Test message sent to +'.$to);
    }
}
