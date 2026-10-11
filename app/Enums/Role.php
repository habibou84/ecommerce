<?php

namespace App\Enums;

/**
 * Rôles d'une personne dans une boutique (spatie/laravel-permission en mode « teams » : store_id).
 * Une même personne peut avoir des rôles différents dans plusieurs boutiques.
 */
enum Role: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case ConfirmationAgent = 'confirmation_agent';
    case StockKeeper = 'stock_keeper';
    case Cashier = 'cashier';
    case Courier = 'courier';
    // Administrateur de la plateforme pendant une session de support (jamais attribué par la boutique)
    case Support = 'support';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Propriétaire',
            self::Manager => 'Gérant',
            self::ConfirmationAgent => 'Agent de confirmation',
            self::StockKeeper => 'Préparateur / magasinier',
            self::Cashier => 'Caissier',
            self::Courier => 'Livreur',
            self::Support => 'Support Jibiat Marchand',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Tous les droits, dont l\'abonnement.',
            self::Manager => 'Tous les droits sauf l\'abonnement.',
            self::ConfirmationAgent => 'Appelle les clients et confirme les commandes.',
            self::StockKeeper => 'Catalogue, stock, préparation des colis.',
            self::Cashier => 'Encaissements, versements des livreurs, finances.',
            self::Courier => 'Application livreur uniquement.',
            self::Support => 'Accès temporaire de l\'équipe Jibiat Marchand, sans l\'abonnement, l\'équipe ni les comptes de paiement.',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Manager => array_values(array_filter(Permission::cases(), fn (Permission $p) => $p !== Permission::BillingManage)),
            self::ConfirmationAgent => [Permission::OrdersView, Permission::OrdersConfirm, Permission::CustomersView, Permission::ProductsView],
            self::StockKeeper => [Permission::ProductsView, Permission::ProductsManage, Permission::StockManage, Permission::OrdersView, Permission::ShipmentsManage],
            self::Cashier => [Permission::OrdersView, Permission::FinanceView, Permission::CustomersView],
            self::Courier => [],
            self::Support => array_values(array_filter(Permission::cases(), fn (Permission $p) => ! in_array($p, [Permission::BillingManage, Permission::TeamManage, Permission::PaymentsManage], true))),
        };
    }

    /**
     * Rôles qu'on peut donner par invitation (le propriétaire est unique ; les livreurs arrivent en phase 5).
     *
     * @return list<self>
     */
    public static function invitable(): array
    {
        return [self::Manager, self::ConfirmationAgent, self::StockKeeper, self::Cashier];
    }

    /**
     * @return list<string>
     */
    public static function invitableValues(): array
    {
        return array_map(fn (self $r) => $r->value, self::invitable());
    }
}
