<?php

namespace App\Http\Controllers\Storefront;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Jobs\SendPurchaseConversion;
use App\Models\Order;
use App\Services\Customers\Loyalty;
use App\Services\Payments\PaymentDesk;
use App\Services\Places\PlaceMatcher;
use App\Services\Places\Places;
use App\Services\Storefront\CartService;
use App\Services\Storefront\Checkout;
use App\Services\Storefront\OnlinePayment;
use App\Services\Storefront\StorefrontView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Jibiat\Socle\PhoneNumber;
use Jibiat\Socle\Rules\PhoneNumber as PhoneRule;

/**
 * Commande en une page : coordonnées, quartier (frais calculés aussitôt), paiement à la livraison.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly Checkout $checkout,
        private readonly StorefrontView $view,
        private readonly OnlinePayment $onlinePayment,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $store = $this->currentStore();
        $cart = $this->carts->find($request, $store);
        $summary = $this->carts->summary($store, $cart);
        if ($summary['lines'] === []) {
            return redirect('/panier');
        }

        return view('storefront.checkout', $this->view->shared($request, $store) + [
            'summary' => $summary,
            'cart' => $cart,
            'pickupPoints' => $this->checkout->pickupPoints($store),
            'payment' => $this->onlinePayment->options($store, $summary['subtotal']),
            'referral' => ($loyalty = app(Loyalty::class)->settings($store))['referral_enabled'] ? $loyalty['referral_discount'] : null,
            'meta' => ['title' => 'Commande · '.$store->name, 'description' => '', 'path' => '/commande', 'noindex' => true],
        ]);
    }

    /**
     * Recherche du quartier (champ du formulaire).
     */
    public function places(Request $request, Places $places, PlaceMatcher $matcher): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:2', 'max:60']]);

        return response()->json(['data' => $matcher->search($request->query('q'), $places->forStore($this->currentStore()), 8)
            ->map(fn ($p) => ['id' => $p->id, 'label' => $p->label()])]);
    }

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate(['delivery_method' => ['required', 'in:delivery,pickup'], 'place_id' => ['nullable', 'integer']]);
        $store = $this->currentStore();
        $cart = $this->carts->find($request, $store) ?? abort(404);

        return response()->json(['data' => $this->checkout->quote($store, $cart, $data['delivery_method'], $data['place_id'] ?? null)]);
    }

    /**
     * Numéro saisi : gardé avec le panier pour une relance si la commande n'aboutit pas (phase 6).
     */
    public function contact(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:30']]);
        $store = $this->currentStore();
        $cart = $this->carts->find($request, $store) ?? abort(404);
        $this->carts->saveContact($cart, $data['name'] ?? null, PhoneNumber::normalize($data['phone'] ?? null));

        return response()->json(['message' => 'OK']);
    }

    public function place(Request $request): JsonResponse
    {
        if (filled($request->input('website'))) {
            abort(422, 'Requête refusée.'); // champ piège invisible : robots
        }
        if (is_string($request->input('phone')) && PhoneNumber::isValid($request->input('phone'))) {
            $request->merge(['phone' => PhoneNumber::normalize($request->input('phone'))]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', new PhoneRule],
            'delivery_method' => ['required', Rule::in(['delivery', 'pickup'])],
            'place_id' => ['nullable', 'integer'],
            'address' => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'pickup_location_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:1000'],
            'payment' => ['nullable', Rule::in(['cod', 'online'])],
            'payment_method' => ['nullable', Rule::in(array_keys(PaymentDesk::METHODS))],
            'accepts_marketing' => ['nullable', 'boolean'],
            'referral_code' => ['nullable', 'string', 'max:12'],
        ], [], ['name' => 'nom', 'phone' => 'téléphone', 'place_id' => 'quartier', 'payment_method' => 'moyen de paiement']);

        $store = $this->currentStore();
        $cart = $this->carts->find($request, $store) ?? abort(422, 'Votre panier est vide.');

        // Paiement en ligne : au choix du client, ou exigé (total ou acompte) par la boutique
        $options = $this->onlinePayment->options($store);
        $online = $options['mode'] === 'required' || $options['mode'] === 'deposit' || ($options['mode'] === 'optional' && ($data['payment'] ?? 'cod') === 'online');
        if ($online && ! array_key_exists($data['payment_method'] ?? '', $options['methods'])) {
            throw new BusinessRuleException('Choisissez votre moyen de paiement.', 'payment_method');
        }

        $order = $this->checkout->place($store, $cart, $data);
        // Accord du client pour les nouveautés par WhatsApp (relances) ; une case non cochée ne retire pas un accord donné
        if (! empty($data['accepts_marketing']) && $order->customer) {
            $order->customer->forceFill(['accepts_marketing' => true, 'marketing_opt_out_at' => null])->save();
        }
        $thanks = '/commande/merci/'.$order->tracking_token;

        // Achat envoyé à Meta par le serveur (API Conversions), si la boutique l'a configurée
        if ($store->marketingId('meta_pixel_id') && filled($store->meta_capi_token)) {
            SendPurchaseConversion::dispatch($order->id, [
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'fbp' => $request->cookie('_fbp'),
                'fbc' => $request->cookie('_fbc'),
                'url' => $store->url('/commande'),
            ]);
        }
        if (! $online) {
            return response()->json(['redirect' => $thanks], 201);
        }

        try {
            $payment = $this->onlinePayment->payOrder($order, $data['payment_method'], $options['mode'] === 'deposit');
        } catch (BusinessRuleException) {
            // Commande enregistrée ; le client pourra payer depuis la page de remerciement
            return response()->json(['redirect' => $thanks.'?paiement=indisponible'], 201);
        }

        return response()->json(['redirect' => $payment->checkout_url], 201);
    }

    public function thanks(Request $request, string $token): View
    {
        $store = $this->currentStore();
        $order = Order::where('tracking_token', $token)->with('items')->firstOrFail();

        return view('storefront.thanks', $this->view->shared($request, $store) + [
            'order' => $order,
            'payment' => $this->onlinePayment->options($store),
            'paymentUnavailable' => $request->query('paiement') === 'indisponible',
            'whatsappText' => 'Bonjour, je viens de passer la commande '.$order->reference().' sur votre boutique.',
            'meta' => ['title' => 'Merci · '.$store->name, 'description' => '', 'path' => '/commande/merci', 'noindex' => true],
        ]);
    }
}
