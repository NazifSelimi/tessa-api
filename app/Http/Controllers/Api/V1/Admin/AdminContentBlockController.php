<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ContentBlockController;
use App\Http\Controllers\Controller;
use App\Models\ContentBlock;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminContentBlockController extends ContentBlockController
{
    public function index(Request $request)
    {
        return ApiResponse::ok(
            ContentBlock::query()->orderBy('page')->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (ContentBlock $block) => $this->payload($block))
        );
    }

    public function store(Request $request)
    {
        $block = ContentBlock::create($this->validated($request));

        return ApiResponse::ok($this->payload($block), 201);
    }

    public function update(Request $request, ContentBlock $contentBlock)
    {
        $contentBlock->update($this->validated($request, $contentBlock));

        return ApiResponse::ok($this->payload($contentBlock->fresh()));
    }

    public function destroy(ContentBlock $contentBlock)
    {
        $contentBlock->delete();

        return ApiResponse::ok(null, 200, [], 'Content block deleted successfully.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ContentBlock $contentBlock = null): array
    {
        $required = $contentBlock ? 'sometimes' : 'required';

        return $request->validate([
            'key' => [$required, 'string', 'max:120', 'regex:/^[a-z0-9._-]+$/', Rule::unique('content_blocks', 'key')->ignore($contentBlock?->id)],
            'page' => [$required, 'string', 'max:80'],
            'section' => [$required, 'string', 'max:80'],
            'kind' => [$required, Rule::in(['hero', 'grid', 'rich_text', 'cta'])],
            'eyebrow' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'action_label' => ['nullable', 'string', 'max:120'],
            'action_url' => ['nullable', 'string', 'max:2048'],
            'items' => ['nullable', 'array', 'max:24'],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.body' => ['nullable', 'string', 'max:2000'],
            'items.*.imageUrl' => ['nullable', 'url', 'max:2048'],
            'items.*.actionLabel' => ['nullable', 'string', 'max:120'],
            'items.*.actionUrl' => ['nullable', 'string', 'max:2048'],
            'is_published' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);
    }
}
