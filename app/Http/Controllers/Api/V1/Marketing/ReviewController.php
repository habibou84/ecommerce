<?php

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use App\Services\Marketing\ReviewBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Avis clients à valider : publier, masquer ou supprimer.
 */
class ReviewController extends Controller
{
    public function __construct(private readonly ReviewBook $reviews) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:pending,published,hidden']]);
        $page = ProductReview::with(['product:id,title,slug', 'order:id,number,store_id'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('id')->paginate(30);

        return response()->json([
            'data' => $page->getCollection()->map(fn (ProductReview $r) => $this->present($r)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'counts' => ProductReview::query()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function publish(int $review): JsonResponse
    {
        $model = ProductReview::findOrFail($review);
        $this->reviews->publish($model);

        return response()->json(['data' => $this->present($model->fresh(['product', 'order']))]);
    }

    public function hide(int $review): JsonResponse
    {
        $model = ProductReview::findOrFail($review);
        $this->reviews->hide($model);

        return response()->json(['data' => $this->present($model->fresh(['product', 'order']))]);
    }

    public function destroy(int $review): JsonResponse
    {
        ProductReview::findOrFail($review)->delete();

        return response()->json(['message' => 'Avis supprimé.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ProductReview $r): array
    {
        return [
            'id' => $r->id,
            'product' => $r->product ? ['id' => $r->product->id, 'title' => $r->product->title] : null,
            'order' => $r->order ? ['id' => $r->order->id, 'reference' => $r->order->reference()] : null,
            'author_name' => $r->author_name,
            'rating' => $r->rating,
            'comment' => $r->comment,
            'status' => $r->status,
            'status_label' => ProductReview::STATUSES[$r->status] ?? $r->status,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
