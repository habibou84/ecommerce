import http from './http'
import { boot } from './boot'
import { facebookRelayUrl, openFacebookRelay } from './facebook'

/**
 * « Connecter WhatsApp » hors des Réglages : directement la fenêtre Facebook (comme le bouton de Réglages › WhatsApp),
 * puis retour sur la page `after` une fois connecté. Sans connexion en un clic configurée : Réglages › WhatsApp.
 */
export async function connectWhatsappDirect(router, after) {
  try {
    sessionStorage.setItem('jm.after-whatsapp', after)
  } catch {
    // Sans stockage : la personne revient par le menu
  }
  try {
    const e = (await http.get('/whatsapp')).data.data?.embedded
    if (e) {
      openFacebookRelay(facebookRelayUrl(boot.platformUrl, { appId: e.app_id, configId: e.config_id, version: e.graph_version, coexistence: 'auto' }, `${window.location.origin}/gestion/whatsapp`, `${window.location.origin}${after}`), '/gestion/whatsapp')
      return
    }
  } catch {
    // Réglages indisponibles : on passe par la page WhatsApp
  }
  router.push('/gestion/whatsapp?onglet=messages')
}
