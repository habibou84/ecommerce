<?php

namespace App\Services\Accounts;

use App\Models\StoreExport;
use App\Support\SpreadsheetCell;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Export complet des données d'une boutique : une archive ZIP de fichiers CSV (séparateur « ; » et UTF-8, pour
 * Excel en français) — produits et stock, clients, commandes et articles, paiements, livraisons, dépenses,
 * fournisseurs, avis. Gardée 7 jours sur le disque privé.
 */
class StoreDataExport
{
    public const KEEP_DAYS = 7;

    public function build(StoreExport $export): StoreExport
    {
        $storeId = $export->store_id;
        $dir = storage_path('app/private/exports/'.$storeId);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Dossier des exports impossible à créer.');
        }
        $file = $dir.'/export-'.$export->id.'-'.now()->format('Ymd-His').'.zip';

        try {
            $zip = new ZipArchive;
            if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Archive impossible à créer.');
            }
            foreach ($this->tables($storeId) as $name => [$headers, $query, $map]) {
                $zip->addFromString($name.'.csv', $this->csv($headers, $query, $map));
            }
            $zip->close();

            $export->forceFill([
                'status' => 'ready', 'path' => 'exports/'.$storeId.'/'.basename($file), 'size' => filesize($file),
                'completed_at' => now(), 'expires_at' => now()->addDays(self::KEEP_DAYS),
            ])->save();
        } catch (Throwable $e) {
            report($e);
            @unlink($file);
            $export->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250), 'completed_at' => now()])->save();
        }

        return $export;
    }

    public function absolutePath(StoreExport $export): string
    {
        return storage_path('app/private/'.$export->path);
    }

    /**
     * Exports expirés supprimés (fichier et ligne).
     */
    public function purge(): int
    {
        $count = 0;
        StoreExport::withoutGlobalScopes()->where('expires_at', '<', now())->get()->each(function (StoreExport $e) use (&$count) {
            if ($e->path) {
                @unlink($this->absolutePath($e));
            }
            $e->delete();
            $count++;
        });

        return $count;
    }

    /**
     * @return array<string, array{0: list<string>, 1: Builder, 2: callable(object): list<mixed>}>
     */
    private function tables(int $storeId): array
    {
        $t = fn (string $table) => DB::table($table)->where("{$table}.store_id", $storeId);

        return [
            'produits' => [
                ['Produit', 'Variante', 'SKU', 'Code-barres', 'Prix', 'Prix barré', "Prix d'achat", 'Stock', 'Réservé', 'Statut', 'En ligne', 'Lot', 'Précommande'],
                $t('product_variants')->join('products as p', 'p.id', '=', 'product_variants.product_id')->whereNull('product_variants.deleted_at')->whereNull('p.deleted_at')
                    ->leftJoinSub(DB::table('stock_levels')->groupBy('variant_id')->selectRaw('variant_id, SUM(on_hand) AS on_hand, SUM(reserved) AS reserved'), 'l', 'l.variant_id', '=', 'product_variants.id')
                    ->select('p.title', 'product_variants.title as variant', 'product_variants.is_default', 'product_variants.sku', 'product_variants.barcode', 'product_variants.price',
                        'product_variants.compare_at_price', 'product_variants.cost_price', 'l.on_hand', 'l.reserved', 'p.status', 'p.is_visible_online', 'p.is_bundle', 'p.preorder_enabled')
                    ->orderBy('p.id')->orderBy('product_variants.position'),
                fn ($r) => [$r->title, $r->is_default ? '' : $r->variant, $r->sku, $r->barcode, $r->price, $r->compare_at_price, $r->cost_price, (int) $r->on_hand, (int) $r->reserved,
                    $r->status, $r->is_visible_online ? 'oui' : 'non', $r->is_bundle ? 'oui' : '', $r->preorder_enabled ? 'oui' : ''],
            ],
            'clients' => [
                ['Nom', 'Téléphone', 'Autre téléphone', 'Email', 'Commandes', 'Livrées', 'Refusées', 'Total dépensé', 'Dernière commande', 'Points de fidélité', 'Accepte les offres', 'Liste noire', 'Note', 'Créé le'],
                $t('customers')->whereNull('deleted_at')->orderBy('id'),
                fn ($r) => [$r->name, $r->phone, $r->phone2, $r->email, $r->orders_count, $r->delivered_count, $r->refused_count, $r->total_spent, $r->last_order_at,
                    $r->loyalty_points, $r->accepts_marketing ? 'oui' : 'non', $r->blacklisted_at ? ($r->blacklist_reason ?: 'oui') : '', $r->note, $r->created_at],
            ],
            'commandes' => [
                ['Numéro', 'Date', 'Statut', 'Paiement', 'Canal', 'Client', 'Téléphone', 'Livraison', 'Quartier', 'Adresse', 'Repère', 'Sous-total', 'Remise', 'Code promo',
                    'Réduction fidélité', 'Réduction parrainage', 'Frais de livraison', 'Total', 'Payé', 'Remboursé', "Prix d'achat", 'Note du client', 'Note interne'],
                $t('orders')->orderBy('number'),
                fn ($r) => ['#'.$r->number, $r->created_at, $r->status, $r->payment_status, $r->source, $r->customer_name, $r->customer_phone, $r->delivery_method === 'pickup' ? 'retrait' : 'livraison',
                    $r->shipping_place_label, $r->shipping_address, $r->shipping_landmark, $r->subtotal, $r->discount_total, $r->discount_code, $r->points_discount, $r->referral_discount,
                    $r->shipping_fee, $r->total, $r->amount_paid, $r->amount_refunded, $r->cost_total, $r->customer_note, $r->internal_note],
            ],
            'articles_commandes' => [
                ['Commande', 'Article', 'Variante', 'SKU', 'Quantité', 'Prix unitaire', 'Remise', 'Total', "Prix d'achat unitaire"],
                $t('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->select('order_items.*', 'orders.number')->orderBy('orders.number')->orderBy('order_items.id'),
                fn ($r) => ['#'.$r->number, $r->title, $r->variant_title, $r->sku, $r->quantity, $r->unit_price, $r->discount_amount, $r->total, $r->unit_cost],
            ],
            'paiements' => [
                ['Commande', 'Date', 'Montant', 'Moyen', 'Type', 'Statut', 'Référence', 'Frais'],
                $t('payments')->join('orders', 'orders.id', '=', 'payments.order_id')->select('payments.*', 'orders.number')->orderBy('payments.id'),
                fn ($r) => ['#'.$r->number, $r->paid_at ?? $r->created_at, $r->amount, $r->method, $r->kind, $r->status, $r->provider_reference, $r->fee],
            ],
            'livraisons' => [
                ['Commande', 'Créée le', 'Statut', 'Mode', 'N° de suivi', 'À encaisser', 'Encaissé', 'Frais transporteur', 'Tentatives'],
                $t('shipments')->join('orders', 'orders.id', '=', 'shipments.order_id')->select('shipments.*', 'orders.number')->orderBy('shipments.id'),
                fn ($r) => ['#'.$r->number, $r->created_at, $r->status, $r->mode, $r->carrier_tracking_code, $r->cod_amount, $r->collected_amount, $r->carrier_fee, $r->attempts_count],
            ],
            'depenses_et_caisse' => [
                ['Date', 'Type', 'Catégorie', 'Canal (publicité)', 'Montant', 'Moyen', 'Note'],
                $t('cash_movements')->orderBy('occurred_at'),
                fn ($r) => [$r->occurred_at, $r->type, $r->category, $r->channel, $r->amount, $r->method, $r->note],
            ],
            'fournisseurs' => [
                ['Nom', 'Téléphone', 'Note'],
                $t('suppliers')->orderBy('name'),
                fn ($r) => [$r->name, $r->phone, $r->note],
            ],
            'avis_clients' => [
                ['Date', 'Produit', 'Note', 'Commentaire', 'Auteur', 'Statut'],
                $t('product_reviews')->join('products as p', 'p.id', '=', 'product_reviews.product_id')->select('product_reviews.*', 'p.title')->orderBy('product_reviews.id'),
                fn ($r) => [$r->created_at, $r->title, $r->rating, $r->comment, $r->author_name, $r->status],
            ],
        ];
    }

    /**
     * @param  list<string>  $headers
     */
    private function csv(array $headers, Builder $query, callable $map): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\u{FEFF}"); // BOM : accents corrects dans Excel
        fputcsv($out, $headers, ';');
        foreach ($query->cursor() as $row) {
            fputcsv($out, array_map(fn ($v) => is_bool($v) ? ($v ? 'oui' : 'non') : SpreadsheetCell::safe($v), $map($row)), ';');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }
}
