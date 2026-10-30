<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\RecommendationService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin Hair quiz page: which products the quiz may recommend, their hair
 * profile tags, and a preview of what a given profile is shown.
 */
class HairQuizController extends Controller
{
    public function __construct(protected RecommendationService $recommendations) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'mode' => ['nullable', Rule::in(RecommendationService::QUIZ_MODES)],
            'tagged' => ['nullable', Rule::in(['yes', 'no'])],
            'stock' => ['nullable', Rule::in(['in', 'out'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = Product::query()
            ->with(['brand:id,name', 'category:id,name', 'hairTypes', 'hairConcerns', 'images' => fn ($q) => $q->orderBy('sort_order')])
            ->orderByRaw("FIELD(quiz_mode, 'featured', 'auto', 'hidden')")
            ->orderBy('name');

        if (filled($data['search'] ?? null)) {
            $query->where('name', 'like', '%' . $data['search'] . '%');
        }
        if (filled($data['mode'] ?? null)) {
            $query->where('quiz_mode', $data['mode']);
        }
        if (($data['tagged'] ?? null) === 'yes') {
            $query->where(fn ($q) => $q->has('hairTypes')->orHas('hairConcerns'));
        } elseif (($data['tagged'] ?? null) === 'no') {
            $query->doesntHave('hairTypes')->doesntHave('hairConcerns');
        }
        if (($data['stock'] ?? null) === 'in') {
            $query->where('quantity', '>', 0);
        } elseif (($data['stock'] ?? null) === 'out') {
            $query->where('quantity', '<=', 0);
        }

        $page = $query->paginate($data['per_page'] ?? 25);

        return ApiResponse::ok(
            collect($page->items())->map(fn (Product $product) => $this->row($product))->values(),
            200,
            ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => $page->perPage(), 'total' => $page->total()]
        );
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'quiz_mode' => ['sometimes', Rule::in(RecommendationService::QUIZ_MODES)],
            'hair_type_ids' => ['sometimes', 'array'],
            'hair_type_ids.*' => ['integer', 'distinct', 'exists:hair_types,id'],
            'hair_concern_ids' => ['sometimes', 'array'],
            'hair_concern_ids.*' => ['integer', 'distinct', 'exists:hair_concerns,id'],
        ]);

        if (array_key_exists('quiz_mode', $data)) {
            $product->quiz_mode = $data['quiz_mode'];
            $product->save();
        }
        if (array_key_exists('hair_type_ids', $data)) {
            $product->hairTypes()->sync($data['hair_type_ids']);
        }
        if (array_key_exists('hair_concern_ids', $data)) {
            $product->hairConcerns()->sync($data['hair_concern_ids']);
        }

        return ApiResponse::ok($this->row($product->fresh(['brand:id,name', 'category:id,name', 'hairTypes', 'hairConcerns', 'images'])));
    }

    /** Runs the real quiz ranking for a customer (no stylist-only products). */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'hair_type_id' => ['required', 'integer', 'exists:hair_types,id'],
            'concerns' => ['nullable', 'array'],
            'concerns.*' => ['integer', 'exists:hair_concerns,id'],
        ]);

        $result = $this->recommendations->getRecommendations((int) $data['hair_type_id'], $data['concerns'] ?? []);

        return ApiResponse::ok(collect($result['products'])->map(fn (Product $product) => $this->row($product) + [
            'score' => (int) $product->recommendation_score,
        ])->values());
    }

    private function row(Product $product): array
    {
        $image = $product->images->first();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'brand' => $product->brand?->name,
            'category' => $product->category?->name,
            'quantity' => (int) $product->quantity,
            'quizMode' => $product->quiz_mode ?? 'auto',
            'hairTypeIds' => $product->hairTypes->pluck('id')->map(fn ($id) => (int) $id)->values(),
            'hairConcernIds' => $product->hairConcerns->pluck('id')->map(fn ($id) => (int) $id)->values(),
            'image' => $image ? ($image->url ?: asset('storage/images/' . $image->name)) : null,
        ];
    }
}
