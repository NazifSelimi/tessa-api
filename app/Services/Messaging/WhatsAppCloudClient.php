<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;

/**
 * Talks to Meta's WhatsApp Cloud API. Only "template" messages are sent: they
 * are the only kind a business may start a conversation with, and Meta must
 * approve each template first. Every successful call may be billed by Meta.
 */
class WhatsAppCloudClient
{
    /**
     * @param  list<string>  $bodyParams  values for {{1}}, {{2}}, ... in the approved template
     * @return array{ok: bool, id: string|null, error: string|null}
     */
    public function sendTemplate(
        string $phoneNumberId,
        string $accessToken,
        string $apiVersion,
        string $to,
        string $template,
        string $language,
        array $bodyParams = [],
    ): array {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language],
            ],
        ];
        if ($bodyParams !== []) {
            $payload['template']['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn ($value) => ['type' => 'text', 'text' => (string) $value], array_values($bodyParams)),
            ]];
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(15)
                ->post("https://graph.facebook.com/$apiVersion/$phoneNumberId/messages", $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'error' => 'Could not reach WhatsApp: '.$e->getMessage()];
        }

        if ($response->successful()) {
            return ['ok' => true, 'id' => $response->json('messages.0.id'), 'error' => null];
        }

        $error = $response->json('error.error_user_msg')
            ?: $response->json('error.message')
            ?: 'WhatsApp answered with HTTP '.$response->status();

        return ['ok' => false, 'id' => null, 'error' => (string) $error];
    }
}
