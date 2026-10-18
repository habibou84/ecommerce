// Libellés et couleurs partagés par les écrans de commandes

export const STATUS_TONES = {
  pending_confirmation: 'bg-amber-100 text-amber-900',
  confirmed: 'bg-sky-100 text-sky-800',
  processing: 'bg-indigo-100 text-indigo-800',
  shipped: 'bg-violet-100 text-violet-800',
  completed: 'bg-emerald-100 text-emerald-800',
  cancelled: 'bg-slate-100 text-slate-600',
  returned: 'bg-red-100 text-red-700',
}

export const PAYMENT_TONES = {
  unpaid: 'text-slate-500',
  partially_paid: 'text-amber-700',
  paid: 'text-emerald-700',
}

export const SOURCES = [
  { value: 'whatsapp', label: 'WhatsApp' },
  { value: 'facebook', label: 'Facebook' },
  { value: 'instagram', label: 'Instagram' },
  { value: 'tiktok', label: 'TikTok' },
  { value: 'phone', label: 'Téléphone' },
  { value: 'in_store', label: 'En boutique' },
  { value: 'manual', label: 'Autre' },
]

export const PAYMENT_METHODS = [
  { value: 'cash', label: 'Espèces' },
  { value: 'wave', label: 'Wave' },
  { value: 'orange_money', label: 'Orange Money' },
  { value: 'mtn_momo', label: 'MTN MoMo' },
  { value: 'moov_money', label: 'Moov Money' },
  { value: 'bank', label: 'Virement' },
  { value: 'other', label: 'Autre' },
]

export const CANCEL_REASONS = [
  { value: 'customer_refused', label: 'Refus du client' },
  { value: 'unreachable', label: 'Client injoignable' },
  { value: 'wrong_number', label: 'Faux numéro' },
  { value: 'out_of_stock', label: 'Rupture de stock' },
  { value: 'duplicate', label: 'Doublon' },
  { value: 'fraud', label: 'Commande suspecte' },
  { value: 'customer_request', label: 'À la demande du client' },
  { value: 'other', label: 'Autre' },
]

export function riskLabel(score) {
  if (score === null || score === undefined) return null
  if (score >= 70) return { label: 'Risque élevé', tone: 'bg-red-100 text-red-700' }
  if (score >= 30) return { label: 'Risque moyen', tone: 'bg-amber-100 text-amber-900' }
  return null
}

/**
 * Libellé lisible d'un événement du journal.
 */
export function eventLabel(event) {
  const d = event.data ?? {}
  switch (event.type) {
    case 'created': return 'Commande créée'
    case 'edited': return 'Commande modifiée'
    case 'status_changed': return `Statut : ${STATUS_LABELS[d.to] ?? d.to}${d.note ? ` (${d.note})` : ''}`
    case 'payment_recorded': return `Paiement de ${new Intl.NumberFormat('fr-FR').format(d.amount)} F${d.online ? ' en ligne' : ''}`
    case 'confirmation_attempt': return `Confirmation : ${OUTCOME_LABELS[d.outcome] ?? d.outcome}${d.note ? ` (${d.note})` : ''}`
    case 'note': return `Note : ${d.note}`
    case 'refund': return `Remboursement de ${new Intl.NumberFormat('fr-FR').format(d.amount)} F${d.method === 'manual' ? ' (à la main)' : ''}${d.reason ? ` : ${d.reason}` : ''}`
    case 'auto_assigned': return `Confiée automatiquement à ${d.to} (${d.reason})`
    case 'auto_assignment_failed': return `Affectation automatique impossible (${d.to}) : ${d.error}`
    case 'customer_eta_reply': return d.response === 'available' ? 'Le client attend le livreur' : 'Le client n\'est pas disponible pour la livraison'
    case 'return_created': return `${d.exchange ? 'Échange' : 'Retour'} ${d.return} : ${new Intl.NumberFormat('fr-FR').format(d.value)} F d'articles rendus`
    case 'return_received': return `${d.return} : articles reçus${d.restocked ? ' et remis en stock' : ''}${d.refunded ? `, ${new Intl.NumberFormat('fr-FR').format(d.refunded)} F remboursés` : ''}`
    case 'return_cancelled': return `${d.return} annulé`
    case 'shipment_created': return `Livraison demandée à ${d.carrier}${d.tracking_code ? ` (n° ${d.tracking_code})` : ''}`
    default: return event.type
  }
}

export const STATUS_LABELS = {
  pending_confirmation: 'À confirmer', confirmed: 'Confirmée', processing: 'En préparation', shipped: 'En route',
  completed: 'Livrée', cancelled: 'Annulée', returned: 'Retournée',
}

export const OUTCOME_LABELS = {
  confirmed: 'Confirmée', no_answer: 'Injoignable', callback: 'À rappeler', changed: 'Modification demandée',
  wrong_number: 'Faux numéro', refused: 'Refusée par le client',
}
