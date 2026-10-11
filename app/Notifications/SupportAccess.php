<?php

namespace App\Notifications;

use App\Models\SupportSession;

/**
 * Le support de la plateforme ouvre un accès temporaire à la gestion de la boutique.
 */
class SupportAccess extends StoreNotification
{
    public function __construct(private readonly SupportSession $session)
    {
        parent::__construct($session->store_id);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'support',
            'title' => 'Accès du support Jibiat Marchand',
            'body' => ($this->session->admin?->name ?? 'Le support').' accède à votre gestion jusqu\'à '.$this->session->expires_at->timezone('Africa/Abidjan')->format('H:i')
                .' : « '.$this->session->reason.' ». Vous pouvez y mettre fin dans Paramètres.',
            'url' => '/gestion/parametres',
        ];
    }
}
