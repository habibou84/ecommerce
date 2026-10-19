// Réglages d'affichage de l'application livreur, gardés sur le téléphone : mode nuit, grands caractères.
import { reactive, watch } from 'vue'

const KEY = 'jm-livreur-prefs'

function read() {
  try {
    return { night: false, big: false, ...JSON.parse(localStorage.getItem(KEY) || '{}') }
  } catch {
    return { night: false, big: false }
  }
}

export const prefs = reactive(read())

// Grands caractères : toute l'application grandit (les tailles sont en rem)
function apply() {
  document.documentElement.style.fontSize = prefs.big ? '118.75%' : ''
}
apply()

watch(prefs, () => {
  apply()
  try {
    localStorage.setItem(KEY, JSON.stringify(prefs))
  } catch {
    // stockage refusé : réglage valable jusqu'à la fermeture
  }
})

// « Ma caisse » vue après la dernière réponse de la boutique à un versement
export function seenDecision(at) {
  try {
    if (at) localStorage.setItem('jm-livreur:decision-vue', at)
  } catch {
    // facultatif
  }
}
export function unseenDecision(at) {
  try {
    return !!at && localStorage.getItem('jm-livreur:decision-vue') !== at
  } catch {
    return false
  }
}
