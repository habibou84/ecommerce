// Notifications push de l'application (Firebase) : le téléphone s'enregistre une fois connecté, et toucher une
// notification ouvre sa page. Sans le module (navigateur, application construite sans Firebase), rien ne se passe.
import { reactive } from 'vue'
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
  if (!push) {
    registerWorker()
    initWebPush()
    return
  }
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
  await forgetWebPush()
  if (!currentToken) return
  await http.delete('/devices', { data: { token: currentToken } }).catch(() => {})
  currentToken = null
}

// ── Notifications du navigateur (Web Push) : ordinateur, gestion ou application livreur installée depuis Chrome ou
// Safari. Dans l'application Android, ce sont les notifications du téléphone ci-dessus.
export const webPush = reactive({
  supported: !plugin() && typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window,
  // iPhone : seulement pour l'application installée sur l'écran d'accueil (iOS 16.4 et plus)
  iosNeedsInstall: typeof navigator !== 'undefined' && /iPhone|iPad|iPod/.test(navigator.userAgent) && !window.matchMedia?.('(display-mode: standalone)').matches && !navigator.standalone,
  serverEnabled: false,
  permission: typeof Notification !== 'undefined' ? Notification.permission : 'default',
  subscribed: false,
  busy: false,
  error: '',
  checked: false,
})
let publicKey = null

function keyToBytes(base64url) {
  const raw = atob((base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)))
}
// Service d'arrière-plan qui reçoit les notifications (commun à la gestion et à l'application livreur)
export function registerWorker() {
  if (!('serviceWorker' in navigator)) return Promise.resolve(null)
  return navigator.serviceWorker.register('/sw.js').catch(() => null)
}
const ready = () => Promise.race([navigator.serviceWorker.ready, new Promise((resolve) => setTimeout(() => resolve(null), 4000))])

export async function initWebPush() {
  if (webPush.checked || !webPush.supported) return
  try {
    const { data } = await http.get('/push')
    webPush.serverEnabled = data.data.enabled
    publicKey = data.data.public_key
  } catch {
    return
  }
  webPush.checked = true
  if (!webPush.serverEnabled) return
  webPush.permission = Notification.permission
  const subscription = await (await ready())?.pushManager.getSubscription()
  webPush.subscribed = Boolean(subscription) && webPush.permission === 'granted'
  // Appareil déjà abonné : rattaché à la personne connectée
  if (webPush.subscribed) http.post('/push/subscriptions', subscription.toJSON()).catch(() => {})
}

export async function enableWebPush() {
  webPush.error = ''
  if (webPush.iosNeedsInstall) {
    webPush.error = 'Sur iPhone, installez d\'abord l\'application : dans Safari, Partager puis « Sur l\'écran d\'accueil ». Ouvrez-la ensuite depuis l\'icône.'
    return false
  }
  webPush.busy = true
  try {
    webPush.permission = await Notification.requestPermission()
    if (webPush.permission !== 'granted') {
      webPush.error = 'Notifications refusées : autorisez-les pour ce site dans les réglages du navigateur.'
      return false
    }
    await registerWorker()
    const reg = await ready()
    if (!reg) {
      webPush.error = 'Pas encore prêt : rechargez la page puis réessayez.'
      return false
    }
    const subscription = (await reg.pushManager.getSubscription()) || (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyToBytes(publicKey) }))
    await http.post('/push/subscriptions', subscription.toJSON())
    webPush.subscribed = true
    return true
  } catch {
    webPush.error = 'Activation impossible sur cet appareil.'
    return false
  } finally {
    webPush.busy = false
  }
}

export async function disableWebPush() {
  webPush.busy = true
  try {
    const subscription = await (await ready())?.pushManager.getSubscription()
    if (subscription) {
      await http.delete('/push/subscriptions', { data: { endpoint: subscription.endpoint } }).catch(() => {})
      await subscription.unsubscribe()
    }
    webPush.subscribed = false
  } finally {
    webPush.busy = false
  }
}

export async function testWebPush(app = 'gestion') {
  return (await http.post('/push/test', { app })).data.data.devices
}

// Déconnexion : cet appareil ne reçoit plus les notifications de la personne
async function forgetWebPush() {
  if (!webPush.supported || !webPush.subscribed) return
  try {
    const subscription = await (await ready())?.pushManager.getSubscription()
    if (subscription) await http.delete('/push/subscriptions', { data: { endpoint: subscription.endpoint } })
  } catch {
    // sans réseau : l'appareil sera rattaché à la prochaine personne connectée
  }
  webPush.subscribed = false
  webPush.checked = false
}
