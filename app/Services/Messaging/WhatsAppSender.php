<?php

namespace App\Services\Messaging;

use App\Models\Order;

/**
 * Sends the automatic order-status WhatsApp messages, but only when an admin
 * connected their Meta account AND switched sending on. Outside production
 * (staging) messages only go to the admin's test number, so a copy of real
 * customer data can never message real customers.
 */
class WhatsAppSender
{
    public const STATUS_KEYS = [
        Order::STATUS_PENDING => 'received',
        Order::STATUS_PAID => 'confirmed',
        Order::STATUS_SHIPPED => 'shipped',
        Order::STATUS_CANCELLED => 'cancelled',
    ];

    public function __construct(
        private readonly MessagingSettings $settings,
        private readonly WhatsAppCloudClient $client,
    ) {}

    /** Who really gets the message: the customer in production, else only the test number. */
    public static function deliverTo(string $customer, bool $production, ?string $testNumber): ?string
    {
        return $production ? $customer : $testNumber;
    }

    /** @return list<string> */
    public static function orderParams(Order $order, int $count): array
    {
        $info = $order->info;
        $name = trim((string) ($info?->first_name ?: $order->user?->first_name ?: ''));
        $total = number_format(round((float) $order->total), 0, ',', '.').' MKD';

        return array_slice([$name !== '' ? $name : '-', (string) $order->id, $total], 0, $count);
    }

    /** @return array{ok: bool, id?: string|null, error?: string|null, skipped?: string} */
    public function orderStatus(Order $order): array
    {
        $status = self::STATUS_KEYS[$order->status] ?? null;
        if (! $status || ! MessagingSettings::premiumAllowed() || ! $this->settings->enabled() || ! $this->settings->connected()) {
            return ['ok' => false, 'skipped' => 'off'];
        }
        $template = $this->settings->statusTemplates()[$status];
        if ($template['name'] === '') {
            return ['ok' => false, 'skipped' => 'no template for '.$status];
        }

        $order->loadMissing(['info', 'user']);
        $customer = PhoneNumber::normalize($order->info?->phone ?: $order->user?->phone);
        if (! $customer) {
            return $this->log($order, $status, ['ok' => false, 'error' => 'No valid phone number']);
        }
        $to = self::deliverTo($customer, app()->isProduction(), $this->settings->testNumber());
        if (! $to) {
            return $this->log($order, $status, ['ok' => false, 'error' => 'Test server: add a test number to receive messages']);
        }

        $result = $this->client->sendTemplate(
            $this->settings->phoneNumberId(),
            (string) $this->settings->accessToken(),
            $this->settings->apiVersion(),
            $to,
            $template['name'],
            $template['language'],
            self::orderParams($order, $template['params']),
        );

        return $this->log($order, $status, $result + ['to' => $to]);
    }

    /** One test message to the number the admin typed, using any approved template. */
    public function test(string $to, string $template, string $language, array $params = []): array
    {
        if (! $this->settings->connected()) {
            return ['ok' => false, 'id' => null, 'error' => 'Save the phone number ID and access token first.'];
        }

        return $this->client->sendTemplate(
            $this->settings->phoneNumberId(),
            (string) $this->settings->accessToken(),
            $this->settings->apiVersion(),
            $to,
            $template,
            $language,
            $params,
        );
    }

    private function log(Order $order, string $status, array $result): array
    {
        $this->settings->remember([
            'order' => $order->id,
            'status' => $status,
            'ok' => (bool) ($result['ok'] ?? false),
            'to' => isset($result['to']) ? substr((string) $result['to'], 0, -3).'***' : null,
            'error' => $result['error'] ?? null,
        ]);

        return $result;
    }
}
