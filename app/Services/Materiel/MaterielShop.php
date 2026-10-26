<?php

namespace App\Services\Materiel;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\Accessory;
use App\Models\AccessoryOrder;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Models\User;
use App\Notifications\MaterielOrderNotice;
use App\Notifications\MaterielOrderToHandle;
use App\Services\Accounts\TeamNotifier;
use App\Services\Billing\BillingSettings;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Jibiat\Socle\PhoneNumber;
use Throwable;

/**
 * Matériel vendu aux e-commerçants : catalogue (Console › Matériel), commande depuis la gestion, paiement Wave
 * (lien marchand de la plateforme, confirmé à la main) ou à la livraison, options de livraison au choix de la
 * plateforme, suivi jusqu'à la livraison. Chaque produit vient du stock de la plateforme ou d'un partenaire
 * (commission). Le stock suivi baisse à la commande et revient à l'annulation.
 */
class MaterielShop
{
    /** Étapes que la console fait avancer, dans l'ordre */
    public const FLOW = ['pending', 'confirmed', 'preparing', 'shipping', 'delivered'];

    public function __construct(private readonly BillingSettings $billing, private readonly TeamNotifier $notifier) {}

    /**
     * @return array{enabled: bool, wave: bool, cod: bool, deliveries: list<array{code: string, label: string, fee: int, note: ?string, address: bool}>}
     */
    public function settings(): array
    {
        $s = PlatformSetting::group('materiel');

        return [
            'enabled' => ($s['enabled'] ?? '0') === '1',
            'wave' => ($s['wave'] ?? '1') === '1',
            'cod' => ($s['cod'] ?? '1') === '1',
            'deliveries' => json_decode($s['deliveries'] ?? '[]', true) ?: [],
        ];
    }

    /**
     * @param  array{enabled: bool, wave: bool, cod: bool, deliveries: list<array{label: string, fee: int, note?: ?string, address?: bool}>}  $data
     */
    public function updateSettings(array $data): array
    {
        if ($data['enabled'] && ! $data['wave'] && ! $data['cod']) {
            throw new BusinessRuleException('Choisissez au moins un moyen de paiement.', 'wave');
        }
        if ($data['enabled'] && $data['deliveries'] === []) {
            throw new BusinessRuleException('Ajoutez au moins une option de livraison.', 'deliveries');
        }
        $deliveries = collect($data['deliveries'])->values()->map(fn ($d, $i) => [
            'code' => Str::slug($d['label']).'-'.($i + 1),
            'label' => trim($d['label']),
            'fee' => (int) $d['fee'],
            'note' => filled($d['note'] ?? null) ? trim($d['note']) : null,
            'address' => (bool) ($d['address'] ?? true),
        ])->all();
        PlatformSetting::put('materiel.enabled', $data['enabled'] ? '1' : '0');
        PlatformSetting::put('materiel.wave', $data['wave'] ? '1' : '0');
        PlatformSetting::put('materiel.cod', $data['cod'] ? '1' : '0');
        PlatformSetting::put('materiel.deliveries', json_encode($deliveries, JSON_UNESCAPED_UNICODE));

        return $this->settings();
    }

    /**
     * Le matériel est proposé aux e-commerçants : boutique ouverte dans la console et au moins un produit en vente.
     */
    public function open(): bool
    {
        return $this->settings()['enabled'] && Accessory::where('is_active', true)->exists();
    }

    /**
     * Moyens de paiement proposés (Wave seulement si le lien ou le QR code de la plateforme est réglé).
     *
     * @return array<string, string>
     */
    public function methods(): array
    {
        $s = $this->settings();

        return array_filter([
            'wave' => $s['wave'] && ($this->billing->waveLink() !== null || $this->billing->qrUrl() !== null) ? AccessoryOrder::PAYMENT_METHODS['wave'] : null,
            'cod' => $s['cod'] ? AccessoryOrder::PAYMENT_METHODS['cod'] : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function presentAccessory(Accessory $a, bool $console = false): array
    {
        $data = [
            'id' => $a->id, 'name' => $a->name, 'category' => $a->category, 'category_label' => Accessory::CATEGORIES[$a->category] ?? $a->category,
            'description' => $a->description, 'price' => $a->price, 'image' => $a->imageUrl(),
            'available' => $a->stock === null || $a->stock > 0, 'stock' => $a->stock,
        ];

        return $console ? $data + $a->only(['cost_price', 'supplier', 'partner_name', 'partner_phone', 'commission_type', 'commission_value', 'is_active', 'sort'])
            + ['earning_per_unit' => $a->earningPerUnit()] : $data;
    }

    /**
     * Commande d'un espace marchand.
     *
     * @param  array{items: list<array{id: int, quantity: int}>, delivery: string, payment: string, contact_name: string, contact_phone: string, address?: ?string, notes?: ?string, payer_phone?: ?string}  $data
     */
    public function order(Store $store, User $user, array $data): AccessoryOrder
    {
        if (! $this->open()) {
            throw new BusinessRuleException('Le matériel n\'est pas proposé pour le moment.');
        }
        if (! array_key_exists($data['payment'], $this->methods())) {
            throw new BusinessRuleException('Moyen de paiement non disponible.', 'payment');
        }
        $delivery = collect($this->settings()['deliveries'])->firstWhere('code', $data['delivery'])
            ?? throw new BusinessRuleException('Choisissez une option de livraison.', 'delivery');
        if ($delivery['address'] && blank($data['address'] ?? null)) {
            throw new BusinessRuleException('Indiquez l\'adresse de livraison.', 'address');
        }
        $phone = PhoneNumber::normalize($data['contact_phone']) ?? throw new BusinessRuleException('Numéro de téléphone invalide.', 'contact_phone');
        $payer = null;
        if ($data['payment'] === 'wave') {
            $payer = PhoneNumber::normalize((string) ($data['payer_phone'] ?? '')) ?? throw new BusinessRuleException('Indiquez le numéro Wave qui va payer.', 'payer_phone');
        }
        $quantities = collect($data['items'])->filter(fn ($l) => $l['quantity'] > 0)->groupBy('id')->map(fn ($ls) => $ls->sum('quantity'));
        if ($quantities->isEmpty()) {
            throw new BusinessRuleException('Ajoutez au moins un article.', 'items');
        }

        $order = DB::transaction(function () use ($store, $user, $data, $delivery, $phone, $payer, $quantities) {
            $accessories = Accessory::whereIn('id', $quantities->keys())->where('is_active', true)->lockForUpdate()->get()->keyBy('id');
            $lines = [];
            $earnings = 0;
            $known = true;
            foreach ($quantities as $id => $quantity) {
                $a = $accessories->get($id) ?? throw new BusinessRuleException('Un article n\'est plus disponible : rechargez la page.', 'items');
                if ($a->stock !== null && $a->stock < $quantity) {
                    throw new BusinessRuleException($a->stock > 0 ? "Il reste seulement {$a->stock} « {$a->name} »." : "« {$a->name} » est épuisé.", 'items');
                }
                if ($a->stock !== null) {
                    $a->decrement('stock', $quantity);
                }
                $unit = $a->earningPerUnit();
                $known = $known && $unit !== null;
                $earnings += ($unit ?? 0) * $quantity;
                $lines[] = [
                    'id' => $a->id, 'name' => $a->name, 'price' => $a->price, 'quantity' => (int) $quantity, 'total' => $a->price * $quantity,
                    'supplier' => $a->supplier, 'partner_name' => $a->partner_name, 'partner_phone' => $a->partner_phone,
                    'earning' => $unit !== null ? $unit * $quantity : null, 'stock_tracked' => $a->stock !== null,
                ];
            }
            $subtotal = array_sum(array_column($lines, 'total'));
            $order = AccessoryOrder::create([
                'store_id' => $store->id, 'user_id' => $user->id, 'status' => 'pending', 'items' => $lines,
                'subtotal' => $subtotal, 'delivery' => $delivery, 'delivery_fee' => $delivery['fee'], 'total' => $subtotal + $delivery['fee'],
                'earnings' => $known ? $earnings : null, 'payment_method' => $data['payment'], 'payment_status' => 'unpaid', 'payer_phone' => $payer,
                'contact_name' => trim($data['contact_name']), 'contact_phone' => $phone,
                'address' => $delivery['address'] ? trim((string) $data['address']) : null, 'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            ]);
            $order->forceFill(['number' => 'MAT-'.now()->format('Y').'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)])->save();

            return $order;
        });
        $this->alert($order, claimed: false);

        return $order;
    }

    /**
     * Lien de paiement Wave de la plateforme, avec le montant.
     */
    public function waveUrl(AccessoryOrder $order): ?string
    {
        $link = $this->billing->waveLink();

        return match (true) {
            $link === null => null,
            str_contains($link, '{montant}') => str_replace('{montant}', (string) $order->total, $link),
            default => $link.(str_contains($link, '?') ? '&' : '?').'amount='.$order->total,
        };
    }

    /**
     * « J'ai payé » (Wave) : le validateur est prévenu.
     */
    public function claim(AccessoryOrder $order): AccessoryOrder
    {
        if ($order->payment_method !== 'wave' || $order->payment_status !== 'unpaid' || $order->status === 'cancelled') {
            return $order;
        }
        $order->update(['payment_status' => 'claimed', 'claimed_at' => now()]);
        $this->alert($order, claimed: true);

        return $order;
    }

    /**
     * Annulation : par l'e-commerçant tant qu'elle n'est pas confirmée, par la console tant qu'elle n'est pas livrée.
     * Le stock suivi revient.
     */
    public function cancel(AccessoryOrder $order, bool $byConsole = false): AccessoryOrder
    {
        if ($order->status === 'cancelled') {
            return $order;
        }
        if (! $byConsole && $order->status !== 'pending') {
            throw new BusinessRuleException('Cette commande est déjà confirmée : contactez le support pour l\'annuler.');
        }
        if ($order->status === 'delivered') {
            throw new BusinessRuleException('Une commande livrée ne s\'annule pas.');
        }
        DB::transaction(function () use ($order) {
            foreach ($order->items as $line) {
                if ($line['stock_tracked'] ?? false) {
                    Accessory::whereKey($line['id'])->whereNotNull('stock')->increment('stock', $line['quantity']);
                }
            }
            $order->update(['status' => 'cancelled']);
        });
        if ($byConsole) {
            $this->tell($order, 'Commande de matériel annulée', "Votre commande {$order->number} a été annulée. Contactez le support si vous avez une question.");
        }

        return $order;
    }

    /**
     * La console fait avancer la commande (confirmée, en préparation, en livraison, livrée) ; l'e-commerçant est prévenu.
     */
    public function advance(AccessoryOrder $order, string $status): AccessoryOrder
    {
        if (! in_array($status, self::FLOW, true) || ! $order->isOpen()) {
            throw new BusinessRuleException('Étape impossible pour cette commande.', 'status');
        }
        $order->update(['status' => $status, 'delivered_at' => $status === 'delivered' ? now() : $order->delivered_at]);
        // Payée à la livraison : l'argent est encaissé à la remise
        if ($status === 'delivered' && $order->payment_method === 'cod' && $order->payment_status !== 'paid') {
            $order->update(['payment_status' => 'paid', 'paid_at' => now()]);
        }
        $body = match ($status) {
            'confirmed' => "Votre commande {$order->number} est confirmée.",
            'preparing' => "Votre commande {$order->number} est en préparation.",
            'shipping' => "Votre commande {$order->number} est en route ({$order->delivery['label']}).",
            'delivered' => "Votre commande {$order->number} est livrée. Merci !",
            default => null,
        };
        if ($body !== null) {
            $this->tell($order, 'Commande de matériel', $body);
        }

        return $order;
    }

    /**
     * Paiement reçu (ou annulation de cette mention par erreur).
     */
    public function markPaid(AccessoryOrder $order, bool $paid): AccessoryOrder
    {
        $order->update($paid ? ['payment_status' => 'paid', 'paid_at' => now()] : ['payment_status' => $order->claimed_at ? 'claimed' : 'unpaid', 'paid_at' => null]);
        if ($paid) {
            $this->tell($order, 'Paiement reçu', "Le paiement de votre commande {$order->number} ({$this->money($order->total)}) est bien reçu.");
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(AccessoryOrder $o, bool $console = false): array
    {
        $data = [
            'id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'status_label' => AccessoryOrder::STATUSES[$o->status] ?? $o->status,
            'items' => collect($o->items)->map(fn ($l) => ['name' => $l['name'], 'price' => $l['price'], 'quantity' => $l['quantity'], 'total' => $l['total']]
                + ($console ? ['supplier' => $l['supplier'], 'partner_name' => $l['partner_name'], 'partner_phone' => $l['partner_phone'], 'earning' => $l['earning']] : [])),
            'subtotal' => $o->subtotal, 'delivery' => ['label' => $o->delivery['label'], 'fee' => $o->delivery_fee], 'total' => $o->total,
            'payment_method' => $o->payment_method, 'payment_label' => AccessoryOrder::PAYMENT_METHODS[$o->payment_method] ?? $o->payment_method,
            'payment_status' => $o->payment_status, 'payment_status_label' => AccessoryOrder::PAYMENT_STATUSES[$o->payment_status] ?? $o->payment_status,
            'wave_url' => $o->payment_method === 'wave' && $o->payment_status === 'unpaid' && $o->status !== 'cancelled' ? $this->waveUrl($o) : null,
            'wave_qr_url' => $o->payment_method === 'wave' && $o->payment_status === 'unpaid' && $o->status !== 'cancelled' ? $this->billing->qrUrl() : null,
            'contact_name' => $o->contact_name, 'contact_phone' => PhoneNumber::display($o->contact_phone), 'address' => $o->address, 'notes' => $o->notes,
            'can_cancel' => $o->status === 'pending', 'created_at' => $o->created_at->toIso8601String(),
        ];

        return $console ? $data + [
            'store' => $o->store ? ['id' => $o->store->id, 'name' => $o->store->name] : null,
            'payer_phone' => $o->payer_phone ? PhoneNumber::display($o->payer_phone) : null, 'earnings' => $o->earnings,
            'contact_whatsapp' => preg_replace('/\D/', '', $o->contact_phone),
        ] : $data;
    }

    private function tell(AccessoryOrder $order, string $title, string $body): void
    {
        if (($store = $order->store) !== null) {
            $this->notifier->notify($store, Permission::BillingManage->value, new MaterielOrderNotice($store->id, $title, $body));
        }
    }

    /**
     * E-mail au validateur des paiements de la plateforme (si l'envoi des e-mails est réglé).
     */
    private function alert(AccessoryOrder $order, bool $claimed): void
    {
        $email = $this->billing->validatorEmail();
        $mailer = $this->billing->mailer();
        if ($email === null || $mailer === null) {
            return;
        }
        try {
            Notification::route('mail', $email)->notifyNow(new MaterielOrderToHandle([
                'number' => $order->number, 'store' => $order->store?->name ?? '—', 'total' => $this->money($order->total),
                'method' => AccessoryOrder::PAYMENT_METHODS[$order->payment_method], 'payer' => $order->payer_phone ? PhoneNumber::display($order->payer_phone) : null,
                'lines' => collect($order->items)->map(fn ($l) => "{$l['quantity']} × {$l['name']}")->all(), 'delivery' => $order->delivery['label'],
                'console_url' => Tenancy::consoleUrl('/console/materiel'), 'claimed' => $claimed,
            ], $mailer['mailer'], $mailer['from']));
        } catch (Throwable $e) {
            Log::warning('Alerte de commande de matériel non envoyée', ['order' => $order->number, 'error' => $e->getMessage()]);
        }
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', ' ').' F';
    }
}
