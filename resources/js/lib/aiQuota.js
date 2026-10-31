import { reactive } from 'vue'

/**
 * Assistant IA : fenêtre de recharge ouverte de n'importe quel écran (bouton du message rouge, ou réponse
 * « ai_quota » d'une action IA), et comptage local d'une action réussie.
 */
export const aiRecharge = reactive({ open: false, message: '' })

export function openAiRecharge(message = '') {
  aiRecharge.message = message
  aiRecharge.open = true
}

/** Plus aucune action : celles du mois sont utilisées et il n'y a pas de recharge en réserve. */
export const aiExhausted = (ai) => !!ai && ai.limit !== null && ai.used >= ai.limit && (ai.credits ?? 0) <= 0

/** Action réussie : prise sur le quota du mois, puis sur les recharges. */
export function countAiAction(ai) {
  if (!ai) return
  if (ai.limit !== null && ai.used >= ai.limit && ai.credits > 0) ai.credits--
  ai.used++
}
