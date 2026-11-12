import http from './http'

// Fenêtre Facebook de l'inscription intégrée WhatsApp (Embedded Signup). Meta n'accepte le SDK que sur des domaines
// déclarés un par un : la fenêtre s'ouvre depuis la plateforme (page /whatsapp/facebook), qui renvoie le résultat
// à la gestion de la boutique (#whatsapp&code=…).
let sdk = null

function loadSdk(appId, version) {
  sdk ??= new Promise((resolve, reject) => {
    window.fbAsyncInit = () => {
      window.FB.init({ appId, autoLogAppEvents: true, xfbml: false, version })
      resolve(window.FB)
    }
    const script = document.createElement('script')
    script.src = 'https://connect.facebook.net/fr_FR/sdk.js'
    script.async = true
    script.crossOrigin = 'anonymous'
    script.onerror = () => {
      sdk = null
      reject(new Error('Facebook ne répond pas. Vérifiez la connexion internet ou désactivez le bloqueur de publicités.'))
    }
    document.body.appendChild(script)
  })
  return sdk
}

// Téléphone : la fenêtre Facebook (pop-up) s'y ferme souvent avant de démarrer ; on passe par la page entière
// (aller chez Facebook, puis retour sur la plateforme avec le code)
export function prefersRedirect() {
  return window.matchMedia?.('(pointer: coarse)').matches || /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent)
}

// Navigateur intégré de WhatsApp, Facebook, Instagram, TikTok… : les fenêtres et les retours y marchent mal
export function inAppBrowser() {
  return /FBAN|FBAV|FB_IAB|Instagram|WhatsApp|musical_ly|TikTok|Line\/|; wv\)/i.test(navigator.userAgent)
}

// Adresse de retour déclarée chez Meta (Paramètres de Facebook Login › URI de redirection OAuth valides)
export function facebookReturnUrl(platformUrl) {
  return `${platformUrl.replace(/\/$/, '')}/whatsapp/facebook`
}

/**
 * Connexion par la page entière : adresse de la fenêtre Facebook, l'état (boutique de retour, options) revient
 * avec le code.
 */
export function facebookDialogUrl({ appId, configId, version, coexistence = false }, redirectUri, state) {
  const extras = { setup: {}, sessionInfoVersion: '3', ...(coexistence ? { featureType: 'whatsapp_business_app_onboarding' } : {}) }
  const query = new URLSearchParams({
    client_id: appId, config_id: configId, response_type: 'code', override_default_response_type: 'true',
    redirect_uri: redirectUri, state, extras: JSON.stringify(extras),
  })
  return `https://www.facebook.com/${version || 'v25.0'}/dialog/oauth?${query}`
}

// Chargement à l'avance : la fenêtre s'ouvre alors aussitôt le clic (pas bloquée par le navigateur)
export function prepareFacebook({ appId, version }) {
  return loadSdk(appId, version).catch(() => {})
}

/**
 * Ouvre la fenêtre et attend le code (connexion Facebook) et le compte choisi (message de la fenêtre).
 * @returns {Promise<{code: string, waba_id: string, phone_number_id: string|null}>}
 */
export async function connectWhatsApp({ appId, configId, version, coexistence = false }) {
  const FB = await loadSdk(appId, version)

  return new Promise((resolve, reject) => {
    let code = null
    let session = null
    const done = (fn, value) => {
      window.removeEventListener('message', onMessage)
      fn(value)
    }
    const settle = () => {
      if (code && session) done(resolve, { code, ...session })
    }
    function onMessage(event) {
      if (!/(^|\.)facebook\.com$/.test(new URL(event.origin).hostname)) return
      let message
      try {
        message = typeof event.data === 'string' ? JSON.parse(event.data) : event.data
      } catch {
        return
      }
      if (message?.type !== 'WA_EMBEDDED_SIGNUP') return
      // Arrêt : erreur signalée par Meta (message et numéro à donner au support) ou fenêtre quittée à une étape
      if (message.event === 'CANCEL') {
        const data = message.data ?? {}
        if (data.error_message) {
          return done(reject, new Error(`Meta a arrêté la connexion : ${data.error_message}${data.error_id ? ` (code ${data.error_id})` : ''}. Recommencez ; si cela se répète, envoyez ce message au support.`))
        }
        return done(reject, new Error(`Connexion interrompue${data.current_step ? ` à l'étape « ${data.current_step} »` : ''}. Vous pouvez recommencer quand vous voulez.`))
      }
      if (message.event === 'ERROR') return done(reject, new Error(message.data?.error_message || 'Meta a signalé une erreur pendant la connexion.'))
      if (String(message.event).startsWith('FINISH') && message.data?.waba_id) {
        // FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING : numéro gardé dans l'application WhatsApp Business (déjà actif chez Meta)
        session = {
          waba_id: String(message.data.waba_id),
          phone_number_id: message.data.phone_number_id ? String(message.data.phone_number_id) : null,
          coexistence: message.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
        }
        settle()
      }
    }
    window.addEventListener('message', onMessage)

    FB.login(
      (response) => {
        // Sans code : la fenêtre a été fermée (ou n'a pas pu s'ouvrir). Un compte déjà choisi dans la fenêtre attend
        // encore un peu son code au lieu d'afficher tout de suite une erreur.
        if (!response.authResponse?.code) {
          return setTimeout(() => !code && done(reject, new Error(session
            ? 'Facebook n\'a pas renvoyé l\'autorisation. Recommencez : la connexion reprend où elle s\'est arrêtée.'
            : 'La fenêtre Facebook a été fermée avant la fin. Recommencez quand vous voulez.')), session ? 8000 : 1500)
        }
        code = response.authResponse.code
        settle()
        // Code reçu sans compte choisi : la fenêtre a été fermée trop tôt
        setTimeout(() => !session && done(reject, new Error('Aucun numéro WhatsApp n\'a été choisi dans la fenêtre Facebook.')), 15000)
      },
      {
        config_id: configId,
        response_type: 'code',
        override_default_response_type: true,
        extras: { setup: {}, sessionInfoVersion: '3', ...(coexistence ? { featureType: 'whatsapp_business_app_onboarding' } : {}) },
      },
    )
  })
}

// Application installée sur l'écran d'accueil (PWA) : la page de la plateforme n'en fait pas partie, y aller fait
// sortir de l'application.
export function installedApp() {
  return window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true
}

export function randomKey() {
  const bytes = new Uint8Array(24)
  window.crypto.getRandomValues(bytes)
  return [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('')
}

/**
 * Ouvre la fenêtre Facebook de la connexion WhatsApp (page de la plateforme `url`, retour prévu sur `back`).
 * Navigateur : la page entière. Application installée : une fenêtre par-dessus l'application, qui reste ouverte
 * dessous ; la page de la plateforme dépose le résultat sur le serveur et l'application le récupère, puis rouvre
 * `back` avec le résultat (#whatsapp&code=…), sans jamais quitter l'application.
 */
export function openFacebookRelay(url, back) {
  if (!installedApp()) {
    window.location.assign(url)
    return
  }
  const key = randomKey()
  const win = window.open(`${url}&cle=${key}`, '_blank')
  if (!win) {
    window.location.assign(url)
    return
  }
  http.post('/whatsapp/connect-session', { key }).catch(() => {})
  const started = Date.now()
  let busy = false
  const check = async () => {
    if (busy) return
    busy = true
    try {
      const result = (await http.get(`/whatsapp/connect-session/${key}`)).data.data?.result
      if (result) {
        stop()
        const target = new URL(back, window.location.origin)
        window.history.replaceState(null, '', `${target.pathname}#${new URLSearchParams({ whatsapp: '1', ...result })}`)
        window.location.reload()
      }
    } catch {
      // pas encore de réponse : on réessaie
    } finally {
      busy = false
    }
  }
  const timer = window.setInterval(() => (Date.now() - started > 30 * 60 * 1000 ? stop() : check()), 2500)
  const onVisible = () => !document.hidden && check()
  document.addEventListener('visibilitychange', onVisible)
  function stop() {
    window.clearInterval(timer)
    document.removeEventListener('visibilitychange', onVisible)
  }
}

// Gestion de la boutique → page de la plateforme qui ouvre la fenêtre Facebook, puis revient sur `back`.
// coexistence : true = numéro gardé dans l'application WhatsApp Business (console) ; 'auto' = Meta le propose
// seulement si le numéro y est déjà (boutiques).
// `from` : page d'où l'on vient, rouverte par « Revenir » (sinon `back`).
export function facebookRelayUrl(platformUrl, { appId, configId, version, coexistence }, back, from = null) {
  const query = new URLSearchParams({ app: appId, config: configId, v: version, retour: back })
  if (from) query.set('depuis', from)
  if (coexistence) query.set('coexistence', coexistence === 'auto' ? 'auto' : '1')
  return `${platformUrl.replace(/\/$/, '')}/whatsapp/facebook?${query}`
}

// Retour sur la gestion : résultat de la fenêtre Facebook dans l'adresse (#whatsapp&code=…), effacé aussitôt lu
export function takeFacebookResult() {
  if (!window.location.hash.startsWith('#whatsapp')) return null
  const params = new URLSearchParams(window.location.hash.slice(1))
  window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search)
  if (params.get('erreur')) return { error: params.get('erreur') }
  if (!params.get('code')) return null
  return {
    code: params.get('code'),
    // Connexion par la page entière : compte retrouvé par le serveur, adresse de retour redonnée à Meta
    waba_id: params.get('waba_id') || null,
    phone_number_id: params.get('phone_number_id') || null,
    coexistence: params.get('coexistence') === '1',
    redirect_uri: params.get('redirect_uri') || null,
    // Page d'où la connexion a été lancée (même boutique) : on y revient une fois connecté
    depuis: params.get('depuis') || null,
  }
}
