import { credits } from './credits'

/**
 * « Mon offre Jibiat » : l'offre de la boutique (abonnement, essai ou offre donnée) et le solde (commandes et IA),
 * lus une seule fois pour le compteur de l'en-tête, la carte de la page Plus (navigation par glissement) et la page.
 *
 * `level` dit s'il faut agir : null (tout va bien), 'orange' (bientôt) ou 'red' (maintenant). `tab` est l'onglet de
 * la page à ouvrir : le solde quand c'est lui qui manque, l'offre sinon.
 */
const DAY = 86400000
const daysUntil = (iso) => (iso ? Math.ceil((new Date(iso).getTime() - Date.now()) / DAY) : null)
const shortDate = (iso) => new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })

// Échéance proche : le rappel part 3 jours avant (BILLING_REMINDER_DAYS), l'essai est signalé 5 jours avant
const RENEW_DAYS = 3
const TRIAL_DAYS = 5

export function offerState(store) {
  const free = store?.free ?? null
  const sub = store?.subscription ?? null
  const plan = free?.plan ?? sub?.plan ?? 'Gratuit'

  // detail : ce qu'il faut savoir de l'offre, sans son nom (affiché à côté)
  let detail = 'Sans abonnement'
  let planLevel = null
  if (free) {
    detail = free.forever || !free.until ? 'Offerte' : `Offerte jusqu'au ${shortDate(free.until)}`
  } else if (sub?.covered_by) {
    detail = `Payée par ${sub.covered_by}`
  } else if (sub?.status === 'past_due') {
    detail = 'À renouveler'
    planLevel = 'red'
  } else if (sub?.status === 'trialing' && sub.trial_ends_at) {
    const left = daysUntil(sub.trial_ends_at)
    detail = left > 0 ? `Essai gratuit · ${left} jour${left > 1 ? 's' : ''}` : 'Essai terminé'
    if (left <= TRIAL_DAYS) planLevel = 'orange'
  } else if (sub?.status === 'active' && sub.current_period_end) {
    detail = `Jusqu'au ${shortDate(sub.current_period_end)}`
    if (daysUntil(sub.current_period_end) <= RENEW_DAYS) planLevel = 'orange'
  }
  const line = free || sub ? `Offre ${plan} · ${detail.charAt(0).toLowerCase()}${detail.slice(1)}` : 'Offre gratuite'

  const c = credits.data
  const counted = !!c?.paid
  let soldeLevel = null
  if (counted && !c.month_until) {
    if (c.orders <= 0) soldeLevel = 'red'
    else if (c.low) soldeLevel = 'orange'
  }

  const level = planLevel === 'red' || soldeLevel === 'red' ? 'red' : planLevel || soldeLevel
  return {
    plan,
    line,
    detail,
    level,
    planLevel,
    // Solde : affiché seulement quand les commandes sont comptées
    orders: counted ? (c.month_until ? '∞' : c.orders) : null,
    ai: counted ? c.ai : null,
    soldeLevel,
    tab: soldeLevel && soldeLevel !== planLevel ? 'solde' : 'offre',
  }
}

export const offerLink = (state) => (state?.tab === 'solde' ? '/gestion/mon-offre?onglet=solde' : '/gestion/mon-offre')
