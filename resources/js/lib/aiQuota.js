/**
 * Assistant IA : plus d'actions, et comptage local d'une action réussie (quota du mois, puis actions IA du solde).
 * La recharge passe par le solde unique (lib/credits).
 */

/** Plus aucune action : celles du mois sont utilisées et le solde n'a plus d'actions IA. */
export const aiExhausted = (ai) => !!ai && ai.limit !== null && ai.used >= ai.limit && (ai.credits ?? 0) <= 0

/** Action réussie : prise sur le quota du mois, puis sur les actions IA du solde. */
export function countAiAction(ai) {
  if (!ai) return
  if (ai.limit !== null && ai.used >= ai.limit && ai.credits > 0) ai.credits--
  ai.used++
}
