import http from './http'
import { installedApp, randomKey } from './facebook'

/**
 * Connexion Facebook (pixel, catalogue, pub) lancée depuis le menu Boost ou « Suivi publicitaire », avec retour sur
 * la même page (`back` : 'boost' ou 'suivi').
 * Navigateur : la page entière part chez Facebook et revient. Application installée : Facebook s'ouvre par-dessus
 * l'application, qui reste ouverte dessous ; une fois la connexion faite, la fenêtre se ferme toute seule (ou le
 * marchand la ferme) et l'application reprend avec le résultat, sans jamais la quitter.
 */
const PATHS = { boost: '/gestion/boost', suivi: '/gestion/marketing/suivi' }

export async function connectFacebookAds(back) {
  const relay = installedApp() ? randomKey() : null
  const { url } = (await http.post('/marketing/tracking/meta/connect', { back, relay })).data
  if (!relay) {
    window.location.assign(url)
    return
  }
  const win = window.open(url, '_blank')
  if (!win) {
    window.location.assign(url)
    return
  }
  const started = Date.now()
  let busy = false
  const check = async () => {
    if (busy) return
    busy = true
    try {
      const result = (await http.get(`/marketing/tracking/meta/relay/${relay}`)).data.data?.result
      if (result) {
        stop()
        try {
          win.close()
        } catch {
          // fenêtre déjà fermée
        }
        window.location.assign(`${PATHS[back] ?? PATHS.suivi}?${result}`)
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
