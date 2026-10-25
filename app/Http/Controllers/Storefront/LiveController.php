<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\LiveItem;
use App\Services\Live\LiveSales;
use App\Services\Places\PlaceMatcher;
use App\Services\Places\Places;
use App\Services\Storefront\StorefrontView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Jibiat\Socle\Rules\PhoneNumber;

/**
 * Page du live ({boutique}/live, lien fixe à mettre dans la bio) : articles du live en cours avec leur code,
 * pièces restantes en direct, réservation en deux touches (nom, numéro et quartier gardés sur le téléphone).
 */
class LiveController extends Controller
{
    public function __construct(private readonly LiveSales $live, private readonly StorefrontView $view) {}

    public function show(Request $request): View
    {
        $store = $this->currentStore();
        $session = LiveSales::current($store->id);

        return view('storefront.live', $this->view->shared($request, $store) + [
            'live' => $session ? $this->live->state($session, team: false) : null,
            'meta' => ['title' => 'En direct · '.$store->name, 'description' => 'Réservez les articles du live de '.$store->name.'.', 'path' => '/live', 'noindex' => true],
        ]);
    }

    public function state(): JsonResponse
    {
        $session = LiveSales::current($this->currentStore()->id);

        return response()->json(['data' => $session ? $this->live->state($session, team: false) : null]);
    }

    /**
     * Quartiers livrés par la boutique (la page du live marche même si la boutique en ligne est fermée).
     */
    public function places(Request $request, Places $places, PlaceMatcher $matcher): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:2', 'max:60']]);

        return response()->json(['data' => $matcher->search($request->query('q'), $places->forStore($this->currentStore()), 8)
            ->map(fn ($p) => ['id' => $p->id, 'label' => $p->label()])->values()]);
    }

    /**
     * Réservation : plusieurs articles et plusieurs pièces (items), ou un seul article (item_id, ancienne page).
     */
    public function reserve(Request $request): JsonResponse
    {
        if ($request->has('items')) {
            return $this->reserveLines($request);
        }
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30', new PhoneNumber],
            'place_id' => ['required', 'integer'],
            'address' => ['nullable', 'string', 'max:255'],
        ], [], ['name' => 'nom', 'phone' => 'numéro', 'place_id' => 'quartier']);
        $session = LiveSales::current($this->currentStore()->id) ?? abort(404, 'Le live est terminé.');
        $item = LiveItem::forStore($session->store_id)->where('live_session_id', $session->id)->findOrFail($data['item_id']);

        $reservation = $this->live->reserve($item, $data, 'page');

        return response()->json([
            'data' => ['status' => $reservation->status, 'code' => $item->code, 'order' => $reservation->order?->reference()],
            'message' => match (true) {
                $reservation->status === 'waiting' => "La {$item->code} est déjà partie : vous êtes sur la liste d'attente. Si une pièce se libère, elle est pour vous.",
                $reservation->order !== null => "C'est réservé ! Commande {$reservation->order->reference()} enregistrée : la boutique vous appelle pour confirmer la livraison.",
                default => "C'est réservé ! La boutique vous appelle pour confirmer la livraison.",
            },
        ]);
    }

    private function reserveLines(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30', new PhoneNumber],
            'place_id' => ['required', 'integer'],
            'address' => ['nullable', 'string', 'max:255'],
        ], [], ['items' => 'articles', 'name' => 'nom', 'phone' => 'numéro', 'place_id' => 'quartier']);
        $session = LiveSales::current($this->currentStore()->id) ?? abort(404, 'Le live est terminé.');
        $lines = collect($data['items'])->mapWithKeys(fn ($line) => [(int) $line['item_id'] => (int) $line['quantity']])->all();

        $reservations = $this->live->reserveLines($session, $lines, $data, 'page');
        $held = $reservations->where('status', '!=', 'waiting');
        $order = $held->first()?->order;
        $waiting = $reservations->where('status', 'waiting')->map(fn ($r) => $r->item->code)->unique()->values();
        $pieces = $held->count();

        $message = match (true) {
            $pieces === 0 => ($waiting->count() > 1 ? 'Ces articles sont déjà partis' : "La {$waiting->first()} est déjà partie")
                .' : vous êtes sur la liste d\'attente. Si une pièce se libère, elle est pour vous.',
            $order !== null => "C'est réservé ! Commande {$order->reference()} enregistrée ({$pieces} article".($pieces > 1 ? 's' : '').') : la boutique vous appelle pour confirmer la livraison.',
            default => "C'est réservé ! La boutique vous appelle pour confirmer la livraison.",
        };
        if ($pieces > 0 && $waiting->isNotEmpty()) {
            $message .= ' Déjà parti : '.$waiting->implode(', ').' (vous êtes sur la liste d\'attente).';
        }

        return response()->json([
            'data' => [
                'status' => $pieces === 0 ? 'waiting' : ($order !== null ? 'ordered' : 'reserved'),
                'order' => $order?->reference(),
                'reserved' => $pieces,
                'waiting' => $waiting->all(),
            ],
            'message' => $message,
        ]);
    }
}
