<?php

namespace App\Enums;

/**
 * Canal de vente : enregistré pour savoir quel canal vend.
 */
enum OrderSource: string
{
    case OnlineStore = 'online_store';
    case Whatsapp = 'whatsapp';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case Tiktok = 'tiktok';
    case Phone = 'phone';
    case InStore = 'in_store';
    case Manual = 'manual';
    case Import = 'import';
    case Api = 'api';
    case Live = 'live';

    public function label(): string
    {
        return match ($this) {
            self::OnlineStore => 'Site',
            self::Whatsapp => 'WhatsApp',
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::Tiktok => 'TikTok',
            self::Phone => 'Téléphone',
            self::InStore => 'En boutique',
            self::Manual => 'Autre',
            self::Import => 'Import',
            self::Api => 'API',
            self::Live => 'Live',
        };
    }

    /**
     * Canaux qu'on choisit en saisissant une commande à la main.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Whatsapp, self::Facebook, self::Instagram, self::Tiktok, self::Phone, self::InStore, self::Manual];
    }
}
