<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContentBlock;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ContentBlockController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['page' => ['nullable', 'string', 'max:80']]);

        $blocks = ContentBlock::query()
            ->where('is_published', true)
            ->when($data['page'] ?? null, fn ($query, string $page) => $query->where('page', $page))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ContentBlock $block) => $this->payload($block));

        return ApiResponse::ok($blocks);
    }

    /** @return array<string, mixed> */
    protected function payload(ContentBlock $block): array
    {
        return [
            'id' => (string) $block->id,
            'key' => $block->key,
            'page' => $block->page,
            'section' => $block->section,
            'kind' => $block->kind,
            'eyebrow' => $block->eyebrow,
            'title' => $block->title,
            'body' => $block->body,
            'imageUrl' => $block->image_url,
            'actionLabel' => $block->action_label,
            'actionUrl' => $block->action_url,
            'items' => $block->items ?? [],
            'isPublished' => $block->is_published,
            'sortOrder' => $block->sort_order,
            'updatedAt' => $block->updated_at?->toISOString(),
        ];
    }
}
