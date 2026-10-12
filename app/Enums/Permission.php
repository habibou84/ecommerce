<?php

namespace App\Enums;

/**
 * Permissions de la gestion d'une boutique. Elles sont attribuées par les rôles (voir Role::permissions()),
 * jamais directement à une personne.
 */
enum Permission: string
{
    case StoreManage = 'store.manage';
    case TeamManage = 'team.manage';
    case BillingManage = 'billing.manage';
    case ProductsView = 'products.view';
    case ProductsManage = 'products.manage';
    case StockManage = 'stock.manage';
    case OrdersView = 'orders.view';
    case OrdersManage = 'orders.manage';
    case OrdersConfirm = 'orders.confirm';
    case CustomersView = 'customers.view';
    case ShipmentsManage = 'shipments.manage';
    case CouriersManage = 'couriers.manage';
    case CarriersManage = 'carriers.manage';
    case PaymentsManage = 'payments.manage';
    case FinanceView = 'finance.view';
    case CashManage = 'cash.manage';

    public function label(): string
    {
        return match ($this) {
            self::StoreManage => 'Paramètres de la boutique',
            self::TeamManage => 'Gérer l\'équipe',
            self::BillingManage => 'Abonnement et facturation',
            self::ProductsView => 'Voir le catalogue',
            self::ProductsManage => 'Modifier le catalogue',
            self::StockManage => 'Gérer le stock',
            self::OrdersView => 'Voir les commandes',
            self::OrdersManage => 'Créer et modifier les commandes',
            self::OrdersConfirm => 'Confirmer les commandes',
            self::CustomersView => 'Voir les clients',
            self::ShipmentsManage => 'Préparer et expédier',
            self::CouriersManage => 'Gérer les livreurs',
            self::CarriersManage => 'Gérer les transporteurs',
            self::PaymentsManage => 'Gérer les moyens de paiement',
            self::FinanceView => 'Voir les finances',
            self::CashManage => 'Tenir la caisse (ventes au comptoir, dépenses, clôture)',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}
