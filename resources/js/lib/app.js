// Application Android / iOS (Capacitor) : la gestion s'ouvre dans l'application, connexion sur la plateforme.
export const inApp = /JibiatMarchandApp/.test(navigator.userAgent) || !!window.Capacitor?.isNativePlatform?.()

const KEY = 'jm_app_store'

// Dernière boutique ouverte depuis l'application : rouverte directement au lancement suivant
export function rememberedStore() {
  try {
    return JSON.parse(localStorage.getItem(KEY) ?? 'null')
  } catch {
    return null
  }
}

export function rememberStore(store) {
  try {
    localStorage.setItem(KEY, JSON.stringify({ id: store.id, name: store.name, url: store.url }))
  } catch {
    // stockage indisponible : on redemandera la boutique
  }
}

export function forgetStore() {
  try {
    localStorage.removeItem(KEY)
  } catch {
    // rien à faire
  }
}
