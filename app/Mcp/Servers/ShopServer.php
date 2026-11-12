<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Lets an AI assistant (Claude, Cursor, any MCP client) help run the shop:
 * see what needs doing, look up orders and products, check stock, update
 * stock and draft emails. Admin token only (Admin → Features → AI assistant).
 */
#[Name('Shop admin')]
#[Version('1.0.0')]
#[Instructions('Tools for the owner of an online shop. Start with shop_todo to see what needs attention. Money is in Macedonian denars (MKD). Order statuses: pending (waiting for confirmation), confirmed, shipped, cancelled. Never invent data: only report what the tools return. draft_email only previews an email; the owner sends it from the admin.')]
class ShopServer extends Server
{
    protected array $tools = [
        Tools\ShopTodoTool::class,
        Tools\SearchOrdersTool::class,
        Tools\GetOrderTool::class,
        Tools\FindProductsTool::class,
        Tools\LowStockTool::class,
        Tools\SetStockTool::class,
        Tools\EmailOutboxTool::class,
        Tools\DraftEmailTool::class,
    ];
}
