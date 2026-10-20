<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index manquants sur des colonnes très lues (PostgreSQL n'indexe pas les clés étrangères tout seul) : articles d'une
 * commande, commandes d'un client, adresses, conversations, affectations des livreurs, remises utilisées…
 * Créés sans bloquer les écritures (CONCURRENTLY), donc hors transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, array{0: string, 1: string}> nom => [table, colonnes] */
    private const INDEXES = [
        'order_items_order_id_index' => ['order_items', 'order_id'],
        'order_items_store_id_variant_id_index' => ['order_items', 'store_id, variant_id'],
        'order_items_product_id_index' => ['order_items', 'product_id'],
        'orders_store_id_customer_id_index' => ['orders', 'store_id, customer_id'],
        'orders_store_id_created_at_index' => ['orders', 'store_id, created_at'],
        'orders_store_id_completed_at_index' => ['orders', 'store_id, completed_at'],
        'customer_addresses_customer_id_index' => ['customer_addresses', 'customer_id'],
        'conversations_customer_id_index' => ['conversations', 'customer_id'],
        'conversations_order_id_index' => ['conversations', 'order_id'],
        'carts_customer_id_index' => ['carts', 'customer_id'],
        'courier_assignments_shipment_id_index' => ['courier_assignments', 'shipment_id'],
        'discount_usages_customer_id_index' => ['discount_usages', 'customer_id'],
        'discount_usages_order_id_index' => ['discount_usages', 'order_id'],
    ];

    public function up(): void
    {
        $concurrently = DB::getDriverName() === 'pgsql' ? 'CONCURRENTLY ' : '';
        foreach (self::INDEXES as $name => [$table, $columns]) {
            DB::statement("CREATE INDEX {$concurrently}IF NOT EXISTS {$name} ON {$table} ({$columns})");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
