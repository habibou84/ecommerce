// Fenêtre Facebook de l'inscription intégrée WhatsApp (Embedded Signup) : le SDK n'est chargé qu'au clic.
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
      if (message.event === 'CANCEL') return done(reject, new Error('Connexion interrompue. Vous pouvez recommencer quand vous voulez.'))
      if (message.event === 'ERROR') return done(reject, new Error(message.data?.error_message || 'Meta a signalé une erreur pendant la connexion.'))
      if (String(message.event).startsWith('FINISH') && message.data?.waba_id) {
        session = { waba_id: String(message.data.waba_id), phone_number_id: message.data.phone_number_id ? String(message.data.phone_number_id) : null }
        settle()
      }
    }
    window.addEventListener('message', onMessage)

    FB.login(
      (response) => {
        if (!response.authResponse?.code) return done(reject, new Error('Connexion à Facebook annulée.'))
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
