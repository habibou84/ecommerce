<?php

namespace App\Notifications;

use App\Models\StoreExport;

/**
 * Export des données prêt à télécharger.
 */
class ExportReady extends StoreNotification
{
    public function __construct(private readonly StoreExport $export)
    {
        parent::__construct($export->store_id);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'export_ready',
            'title' => $this->export->status === 'ready' ? 'Export de vos données prêt' : 'Export de vos données impossible',
            'body' => $this->export->status === 'ready' ? 'Téléchargez l\'archive dans Réglages › Informations de l\'espace marchand (pendant 7 jours).' : 'Réessayez dans quelques minutes.',
            'url' => '/gestion/parametres',
        ];
    }
}
