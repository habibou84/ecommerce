<?php

use App\Services\Orders\OrderListing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Index des lectures les plus fréquentes qui n'en avaient pas :
 *
 * - liste des commandes, tri par défaut (OrderListing::byDeliveryDay) : index sur les expressions exactes du tri,
 *   la première page se lit dans l'index au lieu de trier toutes les commandes de la boutique ;
 * - recherches « contient » (ILIKE '%…%') des commandes, clients et conversations : index trigramme (pg_trgm) ;
 * - tâches planifiées et purges qui parcouraient des tables entières (orders:expire, carts:recover, model:prune) ;
 * - messages envoyés par boutique.
 *
 * Créés sans bloquer les écritures (CONCURRENTLY), donc hors transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @return array<string, string> nom => « table (colonnes) [WHERE …] » */
    private function btree(): array
    {
        return [
            'orders_store_delivery_day_sort_index' => 'orders (store_id, ('.OrderListing::NEEDS_DATE_SQL.') DESC, ('.OrderListing::DELIVERY_DAY_SQL.') DESC, id DESC)',
            'orders_unconfirmed_online_created_at_index' => "orders (created_at) WHERE source = 'online_store' AND status = 'pending_confirmation'",
            'carts_recoverable_last_activity_at_index' => "carts (last_activity_at) WHERE status = 'active' AND recovery_sent_at IS NULL",
            'carts_last_activity_at_index' => 'carts (last_activity_at)',
            'carrier_webhook_receipts_received_at_index' => 'carrier_webhook_receipts (received_at)',
            'payment_webhook_receipts_received_at_index' => 'payment_webhook_receipts (received_at)',
            'outbound_messages_store_id_created_at_index' => 'outbound_messages (store_id, created_at)',
        ];
    }

    /** @return array<string, array{0: string, 1: string}> nom => [table, colonne] */
    private function trigram(): array
    {
        return [
            'orders_customer_name_trgm_index' => ['orders', 'customer_name'],
            'orders_customer_phone_trgm_index' => ['orders', 'customer_phone'],
            'customers_name_trgm_index' => ['customers', 'name'],
            'customers_phone_trgm_index' => ['customers', 'phone'],
            'conversations_contact_name_trgm_index' => ['conversations', 'contact_name'],
            'conversations_contact_trgm_index' => ['conversations', 'contact'],
        ];
    }

    public function up(): void
    {
        $concurrently = DB::getDriverName() === 'pgsql' ? 'CONCURRENTLY ' : '';
        foreach ($this->btree() as $name => $definition) {
            DB::statement("CREATE INDEX {$concurrently}IF NOT EXISTS {$name} ON {$definition}");
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // pg_trgm est une extension « de confiance » (PostgreSQL 13+) : le propriétaire de la base peut l'activer.
        // Sans elle, les recherches restent correctes, seulement plus lentes : la mise à jour ne doit pas échouer.
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (Throwable $e) {
            Log::warning('Extension pg_trgm indisponible : index de recherche non créés', ['error' => $e->getMessage()]);

            return;
        }

        foreach ($this->trigram() as $name => [$table, $column]) {
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$table} USING gin ({$column} gin_trgm_ops)");
        }
    }

    public function down(): void
    {
        foreach ([...array_keys($this->btree()), ...array_keys($this->trigram())] as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
