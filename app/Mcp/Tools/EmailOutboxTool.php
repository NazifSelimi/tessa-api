<?php

namespace App\Mcp\Tools;

use App\Models\EmailLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Emails the shop sent recently, or only the ones that were NOT sent (failed or still waiting) with the error.')]
class EmailOutboxTool extends Tool
{
    protected string $name = 'email_outbox';

    public function handle(Request $request): Response
    {
        $data = $request->validate(['only_not_sent' => ['nullable', 'boolean'], 'limit' => ['nullable', 'integer', 'between:1,50']]);
        $query = EmailLog::query()->latest('id');
        if ($data['only_not_sent'] ?? true) {
            $query->whereIn('status', EmailLog::NOT_SENT);
        }

        return Response::json($query->limit($data['limit'] ?? 20)->get()->map(fn (EmailLog $log) => [
            'id' => $log->id,
            'to' => $log->to_email,
            'subject' => $log->subject,
            'status' => $log->status,
            'error' => $log->error,
            'order_id' => $log->order_id,
            'when' => $log->created_at?->toDateTimeString(),
        ])->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'only_not_sent' => $schema->boolean()->description('true (default) = only failed / waiting emails'),
            'limit' => $schema->integer()->description('How many (default 20)'),
        ];
    }
}
