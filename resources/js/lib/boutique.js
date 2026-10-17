import { boot } from './boot'

/*
 * Boutique choisie parmi celles de l'adresse (boutique hôte et boutiques hébergées, offre Business).
 * Mémorisée sur l'appareil ; envoyée à l'API par l'en-tête X-Store-Id. Rien de choisi : boutique de l'adresse.
 */
const KEY = 'jm_boutique'

function read() {
  try {
    return Number(localStorage.getItem(KEY)) || null
  } catch {
    return null
  }
}

export function chosenStore() {
  return read()
}

export function chooseStore(id) {
  try {
    if (!id || id === boot.store?.id) localStorage.removeItem(KEY)
    else localStorage.setItem(KEY, String(id))
  } catch {
    // stockage indisponible (navigation privée) : boutique de l'adresse
  }
}

// Lien vers une boutique hébergée (notification, création) : « ?boutique=ID », retiré de l'adresse affichée
const params = new URLSearchParams(window.location.search)
if (params.has('boutique')) {
  chooseStore(Number(params.get('boutique')))
  params.delete('boutique')
  const query = params.toString()
  window.history.replaceState(window.history.state, '', window.location.pathname + (query ? `?${query}` : '') + window.location.hash)
}
