// Expéditions : couleurs et libellés communs aux écrans. Mêmes couleurs que les commandes (charte) :
// demandée = bleu, en route = violet, livrée = vert, à décider = ocre, perdue ou refusée = rouge, revenue ou annulée = gris
export const SHIPMENT_TONES = {
  requested: 'bg-sky-100 text-sky-800',
  accepted: 'bg-sky-100 text-sky-800',
  awaiting_pickup: 'bg-sky-100 text-sky-800',
  in_transit: 'bg-violet-100 text-violet-800',
  out_for_delivery: 'bg-violet-100 text-violet-800',
  delivered: 'bg-emerald-100 text-emerald-800',
  failed: 'bg-ocre-50 text-ocre-800',
  rescheduled: 'bg-ocre-50 text-ocre-800',
  returning: 'bg-ocre-50 text-ocre-800',
  returned: 'bg-slate-100 text-slate-600',
  rejected: 'bg-red-100 text-red-800',
  cancelled: 'bg-slate-100 text-slate-600',
  lost: 'bg-red-100 text-red-800',
}

// Statuts qu'on saisit à la main pour un transporteur sans API
export const MANUAL_STATUSES = [
  { value: 'accepted', label: 'Acceptée' },
  { value: 'in_transit', label: 'Colis récupéré (en route)' },
  { value: 'delivered', label: 'Livrée' },
  { value: 'failed', label: 'Échec de livraison' },
  { value: 'returning', label: 'En retour' },
  { value: 'returned', label: 'Retournée à la boutique' },
  { value: 'cancelled', label: 'Annulée' },
  { value: 'lost', label: 'Perdue' },
]

export const SOURCE_LABELS = { carrier_webhook: 'entreprise de livraison', carrier_sync: 'synchronisation', user: 'saisie', system: 'automatique' }
