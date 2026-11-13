import { reactive } from 'vue'
import http from './http'

/**
 * Solde de la boutique (commandes et actions IA), partagé par tous les écrans : compteur de l'en-tête, page « Mon
 * solde », fenêtre « Recharger » ouverte de n'importe où (fin des actions IA, live qui ne s'ouvre pas, boost).
 */
export const credits = reactive({ data: null, open: false, message: '' })

export async function loadCredits() {
  try {
    credits.data = (await http.get('/credits')).data.data
  } catch {
    // sans solde : le compteur reste caché
  }
  return credits.data
}

export function openRecharge(message = '') {
  credits.message = message
  credits.open = true
  loadCredits()
}

/** Ce qui est compté : payant (live, WhatsApp, boost) ou gratuit (boutique en ligne, saisie à la main) */
export const COUNTED = [
  ['💬', 'Commandes prises par WhatsApp (live compris)', true],
  ['🚀', 'Commandes venues d\'un boost Meta', true],
  ['🛍️', 'Commandes de votre boutique en ligne', false],
  ['✍️', 'Commandes saisies à la main', false],
]
