// Notifications push de l'application (Firebase) : le téléphone s'enregistre une fois connecté, et toucher une
// notification ouvre sa page. Sans le module (navigateur, application construite sans Firebase), rien ne se passe.
import http from './http'

const plugin = () => (/JibiatMarchandPush/.test(navigator.userAgent) ? window.Capacitor?.Plugins?.PushNotifications : null)
let currentToken = null

// À appeler une fois au démarrage, sur toutes les pages (y compris quand l'application s'ouvre depuis une notification)
export function listenToNotificationTaps() {
  const push = plugin()
  push?.addListener('pushNotificationActionPerformed', ({ notification }) => {
    const url = notification?.data?.url
    if (url && url !== window.location.href) window.location.assign(url)
  })
}

// Après la connexion : autorisation (Android 13+), puis enregistrement du téléphone pour la personne connectée
export async function registerDevice() {
  const push = plugin()
  if (!push) return
  try {
    let permission = await push.checkPermissions()
    if (permission.receive === 'prompt' || permission.receive === 'prompt-with-rationale') permission = await push.requestPermissions()
    if (permission.receive !== 'granted') return

    await push.createChannel?.({ id: 'jibiat', name: 'Jibiat Marchand', description: 'Commandes, messages et livraisons', importance: 5, sound: 'default' }).catch(() => {})
    await push.removeAllListeners?.().catch(() => {})
    listenToNotificationTaps()
    push.addListener('registration', async ({ value }) => {
      currentToken = value
      await http.post('/devices', { token: value, platform: window.Capacitor.getPlatform() }).catch(() => {})
    })
    await push.register()
  } catch {
    // notifications indisponibles sur ce téléphone : l'application fonctionne sans
  }
}

// À la déconnexion : ce téléphone ne reçoit plus les notifications de la personne
export async function unregisterDevice() {
  if (!currentToken) return
  await http.delete('/devices', { data: { token: currentToken } }).catch(() => {})
  currentToken = null
}
