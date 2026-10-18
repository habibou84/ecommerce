<?php

namespace App\Services\Marketing;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\WhatsApp\CustomerMessenger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Relance WhatsApp des anciens clients avec le modèle marketing « Nouveautés » : clients qui n'ont pas commandé
 * depuis un moment, acheteurs d'un produit, ou tous. Seuls les clients qui ont accepté de recevoir les offres
 * (ou, sur engagement du marchand, ceux déjà livrés) et qui n'ont pas répondu STOP sont visés ; un client
 * reçoit au plus une relance par semaine. Meta facture chaque message : le coût estimé s'affiche avant l'envoi.
 */
class Campaigns
{
    public const MAX_RECIPIENTS = 5000;

    /** Un client ne reçoit pas deux relances à moins d'une semaine d'écart */
    public const MIN_DAYS_BETWEEN = 7;

    /** Commandes comptées après une relance */
    public const ATTRIBUTION_DAYS = 7;

    public function __construct(private readonly CustomerMessenger $messenger) {}

    /**
     * @param  array{type: string, days?: ?int, product_id?: ?int, include_buyers?: bool}  $audience
     * @return Builder<Customer>
     */
    public function audience(Store $store, array $audience): Builder
    {
        $query = Customer::forStore($store->id)->whereNotNull('phone')->whereNull('blacklisted_at')->whereNull('marketing_opt_out_at')
            ->where(fn ($q) => $q->where('accepts_marketing', true)
                ->when(! empty($audience['include_buyers']), fn ($q) => $q->orWhere('delivered_count', '>', 0)))
            ->where(fn ($q) => $q->whereNull('last_campaign_at')->orWhere('last_campaign_at', '<', now()->subDays(self::MIN_DAYS_BETWEEN)));

        return match ($audience['type']) {
            'inactive' => $query->where('last_order_at', '<', now()->subDays(max(1, (int) ($audience['days'] ?? 60)))),
            'product' => $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereColumn('orders.customer_id', 'customers.id')->where('orders.store_id', $store->id)
                ->where('orders.status', OrderStatus::Completed->value)->where('order_items.product_id', (int) ($audience['product_id'] ?? 0))),
            default => $query,
        };
    }

    /**
     * @param  array{type: string, days?: ?int, product_id?: ?int, include_buyers?: bool}  $audience
     * @return array{recipients: int, estimated_cost: int, consented: int, buyers_without_consent: int, sample: list<string>}
     */
    public function preview(Store $store, array $audience): array
    {
        $count = min(self::MAX_RECIPIENTS, $this->audience($store, $audience)->count());

        return [
            'recipients' => $count,
            'estimated_cost' => $count * $this->price(),
            'consented' => Customer::forStore($store->id)->where('accepts_marketing', true)->whereNull('marketing_opt_out_at')->count(),
            // Clients livrés qui n'ont pas (encore) donné leur accord : visés seulement si le marchand s'y engage
            'buyers_without_consent' => Customer::forStore($store->id)->where('accepts_marketing', false)->whereNull('marketing_opt_out_at')
                ->where('delivered_count', '>', 0)->whereNull('blacklisted_at')->count(),
            'sample' => $this->audience($store, $audience)->orderByDesc('last_order_at')->limit(5)->pluck('name')->filter()->values()->all(),
        ];
    }

    public function price(): int
    {
        return (int) config('services.meta.marketing_message_price', 15);
    }

    /**
     * @param  array{title: string, message: string, link?: ?string, audience: array<string, mixed>}  $data
     */
    public function save(Store $store, ?Campaign $campaign, array $data, User $actor): Campaign
    {
        if ($campaign && $campaign->status !== 'draft') {
            throw new BusinessRuleException('Cette relance est déjà envoyée.');
        }
        if (($data['audience']['type'] ?? null) === 'product' && ! Product::forStore($store->id)->whereKey($data['audience']['product_id'] ?? 0)->exists()) {
            throw new BusinessRuleException('Choisissez le produit acheté.', 'audience.product_id');
        }
        $campaign ??= new Campaign(['store_id' => $store->id, 'created_by' => $actor->id]);
        $campaign->fill([
            'title' => $data['title'],
            // Un paramètre de modèle Meta tient sur une ligne
            'message' => Str::squish($data['message']),
            'link' => $data['link'] ?? null,
            'audience' => $data['audience'],
        ])->save();

        return $campaign;
    }

    public function account(Store $store): ?WhatsappAccount
    {
        $account = WhatsappAccount::forStore($store->id)->first();

        return $account && $account->status === 'active' && filled($account->templates['campaign'] ?? null) ? $account : null;
    }

    /**
     * Envoi : un message toutes les deux secondes, pour ne pas dépasser les limites de Meta.
     */
    public function send(Store $store, Campaign $campaign): Campaign
    {
        $account = $this->account($store);
        if ($account === null) {
            throw new BusinessRuleException('Connectez le WhatsApp de la boutique et créez le modèle « Nouveautés » (Réglages › WhatsApp › Modèles) avant d\'envoyer une relance.');
        }

        return DB::transaction(function () use ($store, $campaign, $account) {
            $campaign = Campaign::forStore($store->id)->lockForUpdate()->findOrFail($campaign->id);
            if ($campaign->status !== 'draft') {
                throw new BusinessRuleException('Cette relance est déjà envoyée.');
            }
            $customers = $this->audience($store, $campaign->audience)->orderByDesc('last_order_at')->limit(self::MAX_RECIPIENTS)->get(['id', 'name', 'phone']);
            if ($customers->isEmpty()) {
                throw new BusinessRuleException('Aucun client à relancer avec ces critères.');
            }

            $link = $campaign->link ?: $store->url('/');
            foreach ($customers->values() as $i => $customer) {
                $first = Str::of((string) $customer->name)->trim()->before(' ')->value() ?: 'cher client';
                $this->messenger->forCampaign($account, $customer->phone, [$first, $store->name, $campaign->message, $link], $campaign->id, now()->addSeconds($i * 2));
            }
            Customer::forStore($store->id)->whereIn('id', $customers->pluck('id'))->update(['last_campaign_at' => now()]);

            $campaign->forceFill([
                'status' => 'sent',
                'recipients' => $customers->count(),
                'estimated_cost' => $customers->count() * $this->price(),
                'sent_at' => now(),
            ])->save();

            return $campaign;
        });
    }

    /**
     * Messages remis et lus, et commandes passées par les clients relancés dans les 7 jours.
     *
     * @return array<string, int>
     */
    public function results(Store $store, Campaign $campaign): array
    {
        if ($campaign->sent_at === null) {
            return [];
        }
        $messages = OutboundMessage::forStore($store->id)->where('related_type', 'campaign')->where('related_id', $campaign->id);
        $phones = (clone $messages)->select('to_phone');
        $orders = Order::forStore($store->id)->whereIn('customer_phone', $phones)
            ->whereBetween('created_at', [$campaign->sent_at, $campaign->sent_at->copy()->addDays(self::ATTRIBUTION_DAYS)])
            ->where('status', '!=', OrderStatus::Cancelled->value);

        return [
            'delivered' => (clone $messages)->whereIn('status', ['delivered', 'read'])->count(),
            'read' => (clone $messages)->where('status', 'read')->count(),
            'failed' => (clone $messages)->where('status', 'failed')->count(),
            'orders' => (clone $orders)->count(),
            'orders_amount' => (int) (clone $orders)->sum('total'),
            'delivered_amount' => (int) (clone $orders)->where('status', OrderStatus::Completed->value)->sum('total'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Store $store, Campaign $campaign, bool $withResults = true): array
    {
        return [
            'id' => $campaign->id,
            'title' => $campaign->title,
            'message' => $campaign->message,
            'link' => $campaign->link,
            'audience' => $campaign->audience,
            'status' => $campaign->status,
            'recipients' => $campaign->recipients,
            'estimated_cost' => $campaign->estimated_cost,
            'sent_at' => $campaign->sent_at?->toIso8601String(),
            'created_at' => $campaign->created_at->toIso8601String(),
            'results' => $withResults ? $this->results($store, $campaign) : null,
        ];
    }
}
