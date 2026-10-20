<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Marketing\ReviewBook;
use App\Services\Storefront\StorefrontView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Page d'avis du client (lien envoyé après la livraison) : une note et un commentaire par produit reçu.
 */
class ReviewController extends Controller
{
    public function __construct(
        private readonly ReviewBook $reviews,
        private readonly StorefrontView $view,
    ) {}

    public function show(Request $request, string $token): View
    {
        $store = $this->currentStore();
        $order = $this->order($token);

        return view('storefront.review', $this->view->shared($request, $store) + [
            'order' => $order,
            'open' => $this->reviews->canReview($order),
            'products' => $this->reviews->products($order),
            'meta' => ['title' => 'Votre avis · '.$store->name, 'description' => '', 'path' => '/avis/'.$token, 'noindex' => true, 'private' => true],
        ]);
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:60'],
            'ratings' => ['required', 'array', 'max:50'],
            'ratings.*.rating' => ['nullable', 'integer', 'between:1,5'],
            'ratings.*.comment' => ['nullable', 'string', 'max:1000'],
        ], [], ['ratings' => 'notes', 'name' => 'nom']);

        $count = $this->reviews->submit($this->order($token), $data['ratings'], $data['name'] ?? null);

        return response()->json(['message' => $count > 1 ? 'Merci pour vos avis !' : 'Merci pour votre avis !']);
    }

    private function order(string $token): Order
    {
        return Order::where('store_id', $this->currentStore()->id)->where('tracking_token', $token)->with(['items', 'store'])->firstOrFail();
    }
}
