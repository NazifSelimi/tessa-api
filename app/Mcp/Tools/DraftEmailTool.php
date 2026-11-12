<?php

namespace App\Mcp\Tools;

use App\Services\FeatureService;
use App\Services\BusinessProfile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Prepare an email to customers or business accounts and get a link that opens it, filled in, in the admin. Nothing is sent: the owner checks it and presses Send.')]
class DraftEmailTool extends Tool
{
    protected string $name = 'draft_email';

    public function handle(Request $request): Response
    {
        if (! app(FeatureService::class)->enabled('admin_emails')) {
            return Response::error('Emails from the admin are switched off (Admin → Features).');
        }
        $data = $request->validate([
            'audience' => ['required', 'in:customers,business,all,recent_buyers,inactive_buyers'],
            'subject' => ['required', 'string', 'max:190'],
            'heading' => ['nullable', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:5000'],
            'product_ids' => ['nullable', 'array', 'max:8'],
            'product_ids.*' => ['integer'],
        ]);
        $site = rtrim(app(BusinessProfile::class)->get('website'), '/');
        $draft = base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE));

        return Response::text("Draft ready. Open it, check it and press Send:\n{$site}/admin/emails?tab=compose&draft=" . rawurlencode($draft));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'audience' => $schema->string()->enum(['customers', 'business', 'all', 'recent_buyers', 'inactive_buyers'])->description('Who gets it')->required(),
            'subject' => $schema->string()->description('Email subject')->required(),
            'heading' => $schema->string()->description('Big title inside the email'),
            'body' => $schema->string()->description('Text. Blank line = new paragraph, {name} = first name, **bold**')->required(),
            'product_ids' => $schema->array()->items($schema->integer())->description('Up to 8 product ids to show as cards'),
        ];
    }
}
