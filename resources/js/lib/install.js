import { reactive } from 'vue'

// Installation de l'application (console, gestion) : le navigateur propose l'installation par l'événement
// « beforeinstallprompt » (Chrome, Edge, Android) ; on le garde pour un bouton « Installer ». Sur iPhone, il n'existe
// pas : on explique Partager › Sur l'écran d'accueil.
export const install = reactive({ prompt: null, installed: false })

export function listenToInstallPrompt() {
  if (typeof window === 'undefined') return
  install.installed = window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault()
    install.prompt = event
  })
  window.addEventListener('appinstalled', () => {
    install.prompt = null
    install.installed = true
  })
}

export async function promptInstall() {
  if (!install.prompt) return false
  install.prompt.prompt()
  const { outcome } = await install.prompt.userChoice
  install.prompt = null
  return outcome === 'accepted'
}

export const isIos = () => /iphone|ipad|ipod/i.test(navigator.userAgent)
