// Guide de démarrage : l'étape en cours est gardée le temps de la visite, pour proposer d'y revenir depuis l'écran
// du réglage (bandeau en haut de la gestion)
const KEY = 'jm-guide'

export function guideLeave(step) {
  try {
    sessionStorage.setItem(KEY, JSON.stringify({ key: step.key, title: step.title }))
  } catch {
    // stockage refusé : pas de bandeau de retour
  }
}

export function guideCurrent() {
  try {
    return JSON.parse(sessionStorage.getItem(KEY) || 'null')
  } catch {
    return null
  }
}

export function guideClear() {
  try {
    sessionStorage.removeItem(KEY)
  } catch {
    // rien à faire
  }
}
