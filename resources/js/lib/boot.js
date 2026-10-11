// Contexte remis par le serveur dans la page (voir PageController::spa) :
// zone de l'adresse (platform | store), boutique de l'adresse, domaine de la plateforme.
export const boot = window.__BOOT__ ?? { zone: 'platform', store: null, domain: 'localhost' }

export const isPlatform = boot.zone === 'platform'
export const isStore = boot.zone === 'store'

export function storeUrl(slug) {
  return boot.storeUrlTemplate.replace('{slug}', slug)
}
