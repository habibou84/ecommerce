<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\PaymentLink;
use App\Models\PaymentRoute;
use App\Models\PaymentWebhookReceipt;
use App\Models\Refund;
use App\Models\Store;
use App\Models\User;
use App\Services\Orders\OrderDesk;
use App\Services\Payments\Gateways\CinetPayGateway;
use App\Services\Payments\Gateways\GatewayResult;
use App\Services\Payments\Gateways\PayDunyaGateway;
use App\Services\Payments\Gateways\PaymentGateway;
use App\Services\Payments\Gateways\WaveGateway;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Paiement en ligne : le client choisit son moyen (Wave, Orange Money…), le prestataire est choisi par le routage
 * du marchand (avec secours), puis le paiement n'est validé qu'après contrôle auprès du prestataire.
 */
class PaymentDesk
{
    public const METHODS = ['wave' => 'Wave', 'orange_money' => 'Orange Money', 'mtn_momo' => 'MTN MoMo', 'moov_money' => 'Moov Money', 'card' => 'Carte bancaire'];

    public function __construct(private readonly OrderDesk $orders) {}

    public function gateway(string $provider): PaymentGateway
    {
        return match ($provider) {
            'wave' => app(WaveGateway::class),
            'cinetpay' => app(CinetPayGateway::class),
            'paydunya' => app(PayDunyaGateway::class),
            default => throw new BusinessRuleException('Prestataire inconnu.', 'provider'),
        };
    }

    /**
     * Moyens de paiement proposés aux clients (ceux qui ont au moins un compte actif routé).
     *
     * @return array<string, string>
     */
    public function availableMethods(Store $store): array
    {
        $methods = PaymentRoute::forStore($store->id)->whereHas('account', fn ($q) => $q->where('status', 'active'))->distinct()->pluck('method')->all();

        return array_intersect_key(self::METHODS, array_flip($methods));
    }

    /**
     * Lien de paiement d'une commande (un seul ouvert à la fois : le précédent est remplacé).
     */
    public function createLink(Order $order, ?int $amount, ?string $description, ?User $actor, int $validHours = 168): PaymentLink
    {
        if ($this->availableMethods($order->store) === []) {
            throw new BusinessRuleException('Ajoutez d\'abord un compte de paiement (Réglages › Paiement en ligne).');
        }
        if (in_array($order->status->value, ['cancelled', 'returned'], true) || $order->amountDue() <= 0) {
            throw new BusinessRuleException('Il n\'y a rien à payer pour cette commande.');
        }

        PaymentLink::forStore($order->store_id)->where('order_id', $order->id)->where('status', 'open')->update(['status' => 'cancelled']);

        return PaymentLink::create([
            'store_id' => $order->store_id,
            'order_id' => $order->id,
            'token' => Str::random(24),
            'amount' => min($amount ?? $order->amountDue(), $order->amountDue()),
            'description' => $description,
            'expires_at' => now()->addHours($validHours),
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * Ouvre le paiement chez le premier prestataire disponible pour ce moyen ; en cas d'échec, le suivant.
     */
    public function startCheckout(Order $order, int $amount, string $method, PaymentKind $kind, ?PaymentLink $link = null): Payment
    {
        $store = $order->store;
        if ($amount <= 0 || $amount > $order->amountDue()) {
            throw new BusinessRuleException('Montant à payer invalide.', 'amount');
        }

        $routes = PaymentRoute::forStore($store->id)->where('method', $method)->with('account')->orderBy('priority')->get()
            ->filter(fn (PaymentRoute $r) => $r->account?->status === 'active');
        if ($routes->isEmpty()) {
            throw new BusinessRuleException('Ce moyen de paiement n\'est pas proposé par la boutique.', 'method');
        }

        $lastError = null;
        foreach ($routes as $route) {
            $account = $route->account;
            $payment = Payment::create([
                'store_id' => $store->id,
                'order_id' => $order->id,
                'payment_link_id' => $link?->id,
                'kind' => $kind,
                'payment_account_id' => $account->id,
                'provider' => $account->provider,
                'method' => PaymentMethod::from($method),
                'amount' => $amount,
                'currency' => $store->currency,
                'status' => 'pending',
                'idempotency_key' => (string) Str::uuid(),
            ]);

            try {
                $checkout = $this->gateway($account->provider)->createCheckout($account, $payment, [
                    'return_url' => $store->url('/paiement/'.$payment->idempotency_key),
                    'cancel_url' => $store->url('/paiement/'.$payment->idempotency_key.'?annule=1'),
                    'notify_url' => Tenancy::apiUrl('/webhooks/payments/'.$account->webhook_uuid),
                    'description' => 'Commande '.$order->reference().' · '.$store->name,
                    'customer_name' => $order->customer_name,
                    'customer_phone' => $order->customer_phone,
                    'store_name' => $store->name,
                ]);
                $payment->forceFill(['checkout_url' => $checkout->url, 'provider_reference' => $checkout->reference, 'raw' => $checkout->raw])->save();

                return $payment;
            } catch (Throwable $e) {
                $lastError = $e;
                $payment->forceFill(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 250)])->save();
                $account->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 250)])->save();
            }
        }

        report($lastError);

        throw new BusinessRuleException('Le paiement en ligne est indisponible pour le moment. Réessayez ou choisissez le paiement à la livraison.');
    }

    /**
     * Contrôle auprès du prestataire ; un paiement réussi met la commande à jour (une seule fois).
     */
    public function confirm(Payment $payment): Payment
    {
        if ($payment->status !== 'pending' || $payment->account === null) {
            return $payment;
        }

        $result = $this->gateway($payment->provider)->verify($payment->account, $payment);

        return DB::transaction(function () use ($payment, $result) {
            $payment = Payment::forStore($payment->store_id)->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== 'pending') {
                return $payment;
            }

            if ($result->status === GatewayResult::SUCCEEDED) {
                // Sans montant confirmé par le prestataire, rien n'est validé : le paiement reste en attente et sera relu
                if ($result->amount === null) {
                    Log::warning('Paiement réussi sans montant confirmé : laissé en attente', ['payment' => $payment->id, 'provider' => $payment->provider]);

                    return $payment;
                }
                // Compte de paiement resté en mode test : un paiement de démonstration ne valide pas une vraie commande
                if ($payment->account?->mode === 'test' && app()->isProduction()) {
                    $payment->forceFill(['status' => 'failed', 'failure_reason' => 'Compte de paiement en mode test', 'raw' => $result->raw])->save();

                    return $payment;
                }
                if ($result->amount < $payment->amount) {
                    $payment->forceFill(['status' => 'failed', 'failure_reason' => 'Montant reçu inférieur au montant demandé', 'raw' => $result->raw])->save();

                    return $payment;
                }
                $this->succeeded($payment, $result);
            } elseif ($result->status === GatewayResult::FAILED) {
                $payment->forceFill(['status' => 'failed', 'failure_reason' => $result->reason, 'raw' => $result->raw])->save();
            }

            return $payment;
        });
    }

    private function succeeded(Payment $payment, GatewayResult $result): void
    {
        $payment->forceFill([
            'status' => 'succeeded',
            'paid_at' => now(),
            'method' => $result->method ? (PaymentMethod::tryFrom($result->method) ?? $payment->method) : $payment->method,
            'fee' => $result->fee ?? $payment->account->estimatedFee($payment->amount),
            'raw' => $result->raw,
        ])->save();

        $order = $payment->order;
        $this->orders->refreshPaymentStatus($order);
        $this->orders->log($order, 'payment_recorded', ['amount' => $payment->amount, 'method' => $payment->method->value, 'kind' => $payment->kind->value, 'online' => true], null, 'customer');

        if ($payment->payment_link_id) {
            PaymentLink::whereKey($payment->payment_link_id)->update(['status' => 'paid', 'paid_at' => now()]);
        }

        // Le client a payé (tout ou l'acompte) : la commande n'a plus besoin d'être confirmée par téléphone
        $order->refresh();
        if ($order->status === OrderStatus::PendingConfirmation && $order->amount_paid >= ($order->deposit_required ?? 1)) {
            $this->orders->transition($order, OrderStatus::Confirmed, null, ['note' => 'Paiement en ligne reçu']);
        }
    }

    /**
     * Notification d'un prestataire : authentifiée, dédoublonnée, puis paiement contrôlé par son API.
     *
     * @return int code HTTP
     */
    public function webhook(PaymentAccount $account, Request $request): int
    {
        $parsed = $this->gateway($account->provider)->parseWebhook($account, $request);
        if ($parsed === null) {
            return 401;
        }

        $receipt = PaymentWebhookReceipt::firstOrCreate(
            ['payment_account_id' => $account->id, 'event_id' => $parsed['event_id']],
            ['payload' => $request->all() ?: json_decode($request->getContent(), true), 'received_at' => now()],
        );
        if (! $receipt->wasRecentlyCreated && $receipt->processed_at) {
            return 200;
        }

        $payment = Payment::forStore($account->store_id)->where('payment_account_id', $account->id)
            ->where(function ($q) use ($parsed) {
                $key = $parsed['payment_key'];
                // CinetPay renvoie la clé sans tirets
                $q->when($key, fn ($q) => $q->where('idempotency_key', strlen($key) === 32 ? vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($key, 4)) : $key))
                    ->when($parsed['provider_reference'], fn ($q) => $q->orWhere('provider_reference', $parsed['provider_reference']));
            })->first();

        try {
            if ($payment) {
                $this->confirm($payment);
            }
            $receipt->forceFill(['processed_at' => now(), 'error' => $payment ? null : 'Paiement introuvable'])->save();
        } catch (Throwable $e) {
            report($e);
            $receipt->forceFill(['error' => mb_substr($e->getMessage(), 0, 250)])->save();

            return 500;
        }

        return 200;
    }

    /**
     * Remboursement : par l'API quand le prestataire le permet (Wave, montant total), sinon enregistré comme fait à la main.
     */
    public function refund(Order $order, int $amount, ?string $reason, User $actor, bool $manual = false): Refund
    {
        $refundable = $order->amount_paid - $order->amount_refunded;
        if ($amount <= 0 || $amount > $refundable) {
            throw new BusinessRuleException("Le remboursement doit être compris entre 1 et {$refundable} F.", 'amount');
        }

        $payment = $manual ? null : $order->payments()->where('status', 'succeeded')->whereIn('kind', [PaymentKind::Online->value, PaymentKind::Deposit->value])
            ->whereNotNull('payment_account_id')->latest('id')->get()->first(fn (Payment $p) => $p->amount - $p->refundedAmount() >= $amount);

        $viaProvider = false;
        if ($payment && $payment->account) {
            try {
                $viaProvider = $this->gateway($payment->provider)->refund($payment->account, $payment, $amount);
            } catch (GatewayException $e) {
                throw new BusinessRuleException('Remboursement refusé par '.PaymentAccount::PROVIDERS[$payment->provider].' : '.$e->getMessage());
            }
        }

        return DB::transaction(function () use ($order, $amount, $reason, $actor, $payment, $viaProvider) {
            $refund = Refund::create([
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'payment_id' => $viaProvider ? $payment->id : null,
                'amount' => $amount,
                'method' => $viaProvider ? 'provider' : 'manual',
                'reason' => $reason,
                'status' => 'succeeded',
                'refunded_at' => now(),
                'created_by' => $actor->id,
            ]);
            $this->orders->refreshPaymentStatus($order);
            $this->orders->log($order, 'refund', ['amount' => $amount, 'method' => $refund->method, 'reason' => $reason], $actor);

            return $refund;
        });
    }
}
