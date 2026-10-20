<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\PaymentKind;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Services\Payments\PaymentDesk;
use App\Services\Storefront\OnlinePayment;
use App\Services\Storefront\StorefrontView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pages de paiement du client : lien de paiement (envoyé par WhatsApp), paiement d'une commande depuis sa page de
 * remerciement, et retour du prestataire (le paiement est contrôlé auprès de lui avant d'être affiché comme réussi).
 * Elles fonctionnent même si la boutique en ligne n'est pas ouverte.
 */
class PaymentPageController extends Controller
{
    public function __construct(
        private readonly PaymentDesk $payments,
        private readonly OnlinePayment $online,
        private readonly StorefrontView $view,
    ) {}

    public function link(Request $request, string $token): View
    {
        $store = $this->currentStore();
        $link = PaymentLink::where('token', $token)->with('order')->firstOrFail();

        return view('storefront.payment-link', $this->view->shared($request, $store) + [
            'link' => $link,
            'methods' => $this->payments->availableMethods($store),
            'meta' => ['title' => 'Paiement · '.$store->name, 'description' => '', 'path' => '/payer/'.$token, 'noindex' => true, 'private' => true],
        ]);
    }

    public function payLink(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['method' => ['required', Rule::in(array_keys(PaymentDesk::METHODS))]], [], ['method' => 'moyen de paiement']);
        $link = PaymentLink::where('token', $token)->with('order.store')->firstOrFail();
        if (! $link->isOpen() || $link->order === null) {
            throw new BusinessRuleException('Ce lien de paiement n\'est plus valable.');
        }

        $amount = min($link->amount, $link->order->amountDue());
        $payment = $this->payments->startCheckout($link->order, $amount, $data['method'], PaymentKind::Online, $link);

        return response()->json(['redirect' => $payment->checkout_url]);
    }

    /**
     * Payer en ligne le reste dû d'une commande (page de remerciement ou de suivi).
     */
    public function payOrder(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['method' => ['required', Rule::in(array_keys(PaymentDesk::METHODS))]], [], ['method' => 'moyen de paiement']);
        $order = Order::where('tracking_token', $token)->with('store')->firstOrFail();
        if (in_array($order->status->value, ['cancelled', 'returned'], true) || $order->amountDue() <= 0) {
            throw new BusinessRuleException('Il n\'y a rien à payer pour cette commande.');
        }

        $deposit = $order->store->online_payment === 'deposit' && $order->amount_paid === 0;

        return response()->json(['redirect' => $this->online->payOrder($order, $data['method'], $deposit)->checkout_url]);
    }

    /**
     * Retour du client depuis le prestataire : état contrôlé auprès de lui.
     */
    public function result(Request $request, string $key): View
    {
        $store = $this->currentStore();
        $payment = Payment::where('idempotency_key', $key)->with('order')->firstOrFail();
        try {
            $payment = $this->payments->confirm($payment);
        } catch (\Throwable $e) {
            report($e); // prestataire injoignable : le webhook ou un nouvel essai confirmera
        }

        return view('storefront.payment-result', $this->view->shared($request, $store) + [
            'payment' => $payment,
            'order' => $payment->order,
            'cancelled' => $request->boolean('annule'),
            'meta' => ['title' => 'Paiement · '.$store->name, 'description' => '', 'path' => '/paiement', 'noindex' => true, 'private' => true],
        ]);
    }
}
