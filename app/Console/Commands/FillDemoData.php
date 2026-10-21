<?php

namespace App\Console\Commands;

use App\Enums\CancelReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\StockLocation;
use App\Models\Store;
use App\Services\Billing\PlanLimits;
use App\Services\Catalog\Bundles;
use App\Services\Catalog\CatalogWriter;
use App\Services\Finance\RecurringExpenses;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use App\Services\Stock\PurchaseOrders;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Données fictives pour essayer une boutique en conditions réelles (préproduction uniquement) : catalogue avec
 * variantes, lot et précommande, fournisseur et bon de commande, clients, commandes des 6 dernières semaines
 * (livrées, retournées, annulées, en cours), dépenses, avis, fidélité. Aucun message n'est envoyé (WhatsApp,
 * notifications) : les numéros des clients fictifs sont dans la plage +225 07 99 00 xx xx.
 */
class FillDemoData extends Command
{
    protected $signature = 'demo:fill {store : adresse (slug) ou nom de l\'espace marchand} {--orders=60 : nombre de commandes}';

    protected $description = 'Remplir un espace marchand de préproduction avec des données fictives';

    private const NAMES = ['Awa Koné', 'Fatou Diallo', 'Mariam Traoré', 'Aïcha Ouattara', 'Adjoua Kouassi', 'Christelle Yao', 'Salimata Bamba', 'Grace Kouamé',
        'Esther N\'Guessan', 'Rokia Coulibaly', 'Nadia Touré', 'Linda Aka', 'Bintou Cissé', 'Prisca Gnagne', 'Josiane Brou', 'Koffi Kouadio', 'Yao Serge',
        'Ibrahim Konaté', 'Moussa Sangaré', 'Didier Assi', 'Hervé Kacou', 'Jean-Marc Tanoh', 'Inès Ehui', 'Sandrine Amon', 'Clarisse Djè'];

    private const PLACES = ['Angré', 'Siporex', 'Riviera 2', 'Riviera 3', 'Cocody', 'Deux Plateaux', 'Marcory', 'Koumassi', 'Yopougon', 'Abobo', 'Treichville', 'Plateau', 'Bingerville', 'Port-Bouët'];

    public function handle(CatalogWriter $writer): int
    {
        if (app()->environment('production')) {
            $this->error('Interdit en production : cette commande ne sert qu\'à la préproduction ou en local.');

            return self::FAILURE;
        }
        $search = (string) $this->argument('store');
        $store = Store::where('slug', Str::slug($search))->orWhere('slug', $search)->orWhereRaw('LOWER(name) = ?', [mb_strtolower($search)])->first();
        if ($store === null) {
            $this->error("Espace marchand « {$search} » introuvable. Espaces marchands : ".Store::orderBy('name')->pluck('slug')->implode(', '));

            return self::FAILURE;
        }
        $owner = $store->owner;
        app(Tenancy::class)->setStore($store);
        // Rien ne part vers de vrais numéros : messages WhatsApp, notifications et tâches en file sont interceptés
        Notification::fake();
        Bus::fake();
        // Les commandes fictives ne sont pas bloquées par le quota de l'offre
        app()->instance(PlanLimits::class, new class extends PlanLimits
        {
            public function ensureOrderQuota(Store $store): void {}
        });
        app()->forgetInstance(OrderDesk::class);
        $desk = app(OrderDesk::class);

        $this->info("Remplissage de « {$store->name} » ({$store->slug})…");
        app(Places::class)->provisionDefaultZone($store);
        $places = Place::whereNull('store_id')->whereIn('name', self::PLACES)->pluck('id', 'name');
        if ($places->isEmpty()) {
            $this->error('Aucun quartier d\'Abidjan trouvé : lancez d\'abord php artisan db:seed --class=PlacesSeeder.');

            return self::FAILURE;
        }

        $catalog = $this->catalog($store, $writer);
        $this->line('  '.count($catalog).' produits');

        // Fournisseur et bon de commande reçu
        try {
            $location = StockLocation::forStore($store->id)->where('is_active', true)->orderBy('id')->firstOrFail();
            $po = app(PurchaseOrders::class);
            $order = $po->save($store, null, ['supplier_name' => 'Tissus Adjamé (démo)', 'location_id' => $location->id, 'note' => 'Commande fictive',
                'items' => [['variant_id' => $catalog['robe']->variants[0]->id, 'quantity' => 10, 'unit_cost' => 6500], ['variant_id' => $catalog['pagne']->variants[0]->id, 'quantity' => 20, 'unit_cost' => 4000]]], $owner);
            $po->receive($order, [['variant_id' => $catalog['robe']->variants[0]->id, 'quantity' => 10], ['variant_id' => $catalog['pagne']->variants[0]->id, 'quantity' => 20]], $owner);
            $po->pay($store, $order->fresh(), 145000, 'wave', $owner);
        } catch (Throwable $e) {
            $this->warn('  Bon de commande non créé : '.$e->getMessage());
        }

        // Commandes des 6 dernières semaines
        $variants = collect($catalog)->except(['tabaski'])->flatMap(fn (Product $p) => $p->variants)->values();
        $sources = ['whatsapp', 'whatsapp', 'whatsapp', 'facebook', 'facebook', 'instagram', 'tiktok', 'online_store', 'phone'];
        $count = (int) $this->option('orders');
        $made = 0;
        // Presque toutes les commandes datent des mois précédents (elles ne comptent pas dans le quota du mois) ;
        // les 8 dernières sont récentes : en cours, à confirmer, à faire livrer
        $today = CarbonImmutable::now($store->timezone);
        $intoMonth = $today->day;
        $recent = min(8, $count);
        for ($i = 0; $i < $count; $i++) {
            $customer = $i % 25;
            $daysAgo = $i < $count - $recent
                ? $intoMonth + (int) round(55 * (1 - $i / max(1, $count - $recent - 1)))
                : min($intoMonth - 1, $count - 1 - $i);
            $at = $today->subDays($daysAgo)->setTime(random_int(8, 20), random_int(0, 59));
            $at = $at->isAfter($today) ? $today->subMinutes(30 * ($count - $i)) : $at; // pas de commande dans le futur
            Carbon::setTestNow($at);
            // Articles au hasard ; si l'un manque en stock, d'autres sont tirés (5 essais)
            for ($try = 0; $try < 5; $try++) {
                try {
                    $order = $desk->create($store, [
                        'source' => $sources[$i % count($sources)],
                        'customer_name' => self::NAMES[$customer],
                        'customer_phone' => '+22507990000'.str_pad((string) ($customer + 1), 2, '0', STR_PAD_LEFT),
                        'delivery_method' => $i % 11 === 0 ? 'pickup' : 'delivery',
                        'shipping_place_id' => $places->values()[$i % $places->count()],
                        'shipping_address' => 'Adresse fictive '.($i + 1),
                        'shipping_landmark' => 'Repère fictif',
                        'confirmed' => $daysAgo > 1 || $i % 3 !== 0,
                        'items' => $variants->random($i % 3 === 0 ? 2 : 1)->map(fn ($v) => ['variant_id' => $v->id, 'quantity' => random_int(1, 2)])->values()->all(),
                    ], $owner);
                    $this->advance($desk, $order, $i, $daysAgo, $at, $owner);
                    $made++;
                    break;
                } catch (Throwable $e) {
                    if ($try === 4) {
                        $this->warn('  Commande '.($i + 1).' ignorée : '.$e->getMessage());
                    }
                }
            }
        }
        Carbon::setTestNow();
        $this->line("  {$made} commandes");

        // Clients qui acceptent les offres, avis publiés, dépenses, fidélité
        Customer::forStore($store->id)->where('phone', 'like', '+22507990000%')->whereRaw('id % 2 = 0')->update(['accepts_marketing' => true]);
        Customer::forStore($store->id)->where('phone', 'like', '+22507990000%')->get()->each(fn ($c) => $c->forceFill(['note' => 'Client fictif (données de démonstration)'])->save());
        $this->reviews($store);
        $this->expenses($store, $owner);
        if (empty($store->loyalty_settings)) {
            $store->forceFill(['loyalty_settings' => ['enabled' => true, 'points_per_1000' => 1, 'point_value' => 50, 'min_points' => 10,
                'referral_enabled' => true, 'referral_discount' => 1000, 'referral_points' => 20]])->save();
        }

        $this->info('Terminé. Les clients fictifs ont des numéros +225 07 99 00 00 01 à 25 ; aucun message ne leur a été envoyé.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, Product>
     */
    private function catalog(Store $store, CatalogWriter $writer): array
    {
        $make = function (string $key, array $data) use ($store, $writer) {
            $existing = Product::forStore($store->id)->where('title', $data['title'])->with('variants')->first();

            return [$key, $existing ?? $writer->save($store, null, $data + ['description' => 'Article de démonstration.'])];
        };
        $sizes = fn (array $values, int $price, int $cost, int $stock) => collect($values)->map(fn ($v) => ['options' => [$v], 'price' => $price, 'cost_price' => $cost, 'on_hand' => $stock])->all();

        $items = collect([
            $make('robe', ['title' => 'Robe wax évasée', 'options' => [['name' => 'Taille', 'values' => ['S', 'M', 'L', 'XL']]], 'variants' => $sizes(['S', 'M', 'L', 'XL'], 15000, 6500, 15)]),
            $make('ensemble', ['title' => 'Ensemble pagne deux pièces', 'options' => [['name' => 'Taille', 'values' => ['M', 'L']]], 'variants' => $sizes(['M', 'L'], 22000, 9500, 10)]),
            $make('chemise', ['title' => 'Chemise homme bogolan', 'options' => [['name' => 'Taille', 'values' => ['M', 'L', 'XL']]], 'variants' => $sizes(['M', 'L', 'XL'], 12000, 5000, 10)]),
            $make('pagne', ['title' => 'Pagne Vlisco 6 yards', 'variants' => [['price' => 18000, 'cost_price' => 11000, 'sku' => 'VLI-6Y', 'on_hand' => 15]]]),
            $make('sac', ['title' => 'Sac en pagne', 'variants' => [['price' => 9000, 'cost_price' => 3500, 'sku' => 'SAC-PAGNE', 'on_hand' => 12]]]),
            $make('sandales', ['title' => 'Sandales cuir', 'options' => [['name' => 'Pointure', 'values' => ['38', '39', '40', '41']]], 'variants' => $sizes(['38', '39', '40', '41'], 10000, 4000, 6)]),
            $make('foulard', ['title' => 'Foulard satin', 'variants' => [['price' => 4500, 'cost_price' => 1500, 'sku' => 'FOU-SAT', 'on_hand' => 25]]]),
            $make('collier', ['title' => 'Collier perles', 'variants' => [['price' => 3500, 'cost_price' => 1200, 'sku' => 'COL-PERLES', 'on_hand' => 2]]]),
            $make('beurre', ['title' => 'Beurre de karité 250 g', 'variants' => [['price' => 3000, 'cost_price' => 1000, 'sku' => 'KAR-250', 'on_hand' => 30]]]),
            $make('tabaski', ['title' => 'Robe Tabaski brodée (précommande)', 'preorder_enabled' => true, 'preorder_ship_date' => now()->addDays(21)->toDateString(), 'preorder_deposit_percent' => 30,
                'variants' => [['price' => 35000, 'cost_price' => 15000, 'on_hand' => 0]]]),
            $make('pack', ['title' => 'Pack robe + sac', 'variants' => [['price' => 21000]]]),
        ])->mapWithKeys(fn ($pair) => [$pair[0] => $pair[1]->load('variants')])->all();

        if (! $items['pack']->is_bundle) {
            app(Bundles::class)->save($items['pack'], [['variant_id' => $items['robe']->variants[1]->id, 'quantity' => 1], ['variant_id' => $items['sac']->variants[0]->id, 'quantity' => 1]]);
        }

        return $items;
    }

    /**
     * Suite de la vie de la commande selon son ancienneté : livrée (payée), retournée, annulée, en cours ou à confirmer.
     */
    private function advance(OrderDesk $desk, Order $order, int $i, int $daysAgo, CarbonImmutable $at, $owner): void
    {
        if ($order->status !== OrderStatus::Confirmed) {
            return; // reste à confirmer
        }
        if ($daysAgo <= 1) {
            return; // confirmée, à faire livrer (pour essayer l'application livreur)
        }
        if ($i % 9 === 4) {
            $desk->cancel($order, CancelReason::Unreachable, 'Client injoignable (démo)', $owner);

            return;
        }
        Carbon::setTestNow($at->addHours(20));
        $order = $desk->transition($order, OrderStatus::Shipped, $owner);
        if ($daysAgo <= 3 && $i % 2 === 0) {
            return; // en route
        }
        Carbon::setTestNow($at->addHours(26));
        if ($i % 8 === 2) {
            $desk->transition($order, OrderStatus::Returned, $owner, ['note' => 'Refusé à la livraison (démo)']);

            return;
        }
        $order = $desk->transition($order, OrderStatus::Completed, $owner);
        $desk->recordPayment($order, ['amount' => $order->amountDue(), 'method' => $i % 4 === 0 ? PaymentMethod::Wave : PaymentMethod::Cash,
            'kind' => $i % 4 === 0 ? PaymentKind::CodMobile : PaymentKind::CodCash, 'note' => 'Encaissé à la livraison (démo)'], $owner);
    }

    private function reviews(Store $store): void
    {
        $comments = ['Très belle qualité, je recommande !', 'Livraison rapide, merci.', 'Conforme aux photos.', 'Le tissu est magnifique.', 'Un peu grand pour moi mais joli.'];
        Order::forStore($store->id)->where('status', OrderStatus::Completed->value)->with('items')->limit(8)->get()->values()->each(function (Order $order, int $k) use ($store, $comments) {
            $item = $order->items->first();
            if ($item?->product_id === null) {
                return;
            }
            ProductReview::forStore($store->id)->firstOrCreate(['order_id' => $order->id, 'product_id' => $item->product_id], [
                'store_id' => $store->id, 'customer_id' => $order->customer_id, 'author_name' => Str::before((string) $order->customer_name, ' ').' '.mb_substr(Str::afterLast((string) $order->customer_name, ' '), 0, 1).'.',
                'rating' => $k % 5 === 4 ? 4 : 5, 'comment' => $comments[$k % count($comments)], 'status' => $k < 6 ? 'published' : 'pending', 'published_at' => $k < 6 ? now() : null,
            ]);
        });
    }

    private function expenses(Store $store, $owner): void
    {
        if (CashMovement::forStore($store->id)->where('note', 'like', '%(démo)%')->exists()) {
            return;
        }
        foreach ([[30, 'marketing', 'facebook', 25000], [20, 'marketing', 'facebook', 25000], [10, 'marketing', 'tiktok', 15000], [25, 'transport', null, 8000],
            [12, 'packaging', null, 6000], [5, 'transport', null, 5000]] as [$days, $category, $channel, $amount]) {
            CashMovement::forceCreate(['store_id' => $store->id, 'type' => 'expense', 'category' => $category, 'channel' => $channel, 'amount' => $amount,
                'method' => 'wave', 'note' => 'Dépense fictive (démo)', 'occurred_at' => now()->subDays($days), 'created_by' => $owner->id]);
        }
        app(RecurringExpenses::class)->save($store, null, ['category' => 'rent', 'amount' => 75000, 'method' => 'bank', 'day_of_month' => 5, 'note' => 'Loyer du magasin (démo)'], $owner);
    }
}
