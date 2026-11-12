import { computed, ref, watch } from 'vue'
import http from './http'

/**
 * Show du live, côté téléphone : lancer un moment sur l'écran, et le plan du live (étapes proposées d'après les
 * articles, avancement et durée prévue gardés sur ce téléphone).
 */
export async function launch(liveId, type, body = {}) {
  return (await http.post(`/lives/${liveId}/show/moment`, { type, ...body })).data.data
}

export const MOMENT_NAMES = {
  mystery: '🎁 Article mystère', reveal: '✨ Révélation', podium: '🥇 Les 3 premiers', defi: '🎯 Défi', map: '🗺️ Carte du live',
  how: '💬 Comment commander', last: '⏳ Dernière chance', countdown: '⏱ 3-2-1', party: '🎉 Fête', goal: '🎯 Objectif',
  match: '🏆 Quartiers', wheel: '🎡 Roue', react: '❤️ Réactions', question: '❓ Question montrée', announce: '📣 Annonce du mystère',
  flash: '⚡ Vente flash',
}

function readStore(key, fallback) {
  try {
    const raw = localStorage.getItem(key)
    return raw === null ? fallback : JSON.parse(raw)
  } catch {
    return fallback
  }
}
function writeStore(key, value) {
  try {
    localStorage.setItem(key, JSON.stringify(value))
  } catch {
    // sans stockage : gardé le temps de la visite
  }
}

/**
 * Plan du live : il guide l'affichage des articles, pas à pas. Vitrine puis « Comment commander » pour ouvrir,
 * les articles un par un (rappel « Comment commander » tous les 3 articles, vitrine tous les 6), puis le défilement
 * de ce qui reste et la vitrine finale. Les articles épuisés sont sautés, l'article mystère n'y est qu'une fois
 * révélé. Les moments (défi, mystère, roue…) restent dans les conseils du copilote.
 * Étapes faites gardées sur ce téléphone ; montrer un article à la main recale le plan.
 *
 * @param liveRef      ref du live (id, started_at)
 * @param productsRef  produits du live (un par code : code, title, remaining, mystery, revealed)
 */
export function useLivePlan(liveRef, productsRef) {
  const key = (name) => `jm.live-${name}.${liveRef.value.id}`
  const done = ref(new Set(readStore(key('plan-done'), [])))
  // Étape en cours (à l'écran maintenant) et depuis quand : gardée pour reprendre le plan après une coupure
  const now = ref(readStore(key('plan-now'), null)) // { key, at }
  watch(now, (v) => writeStore(key('plan-now'), v))
  const minutes = ref(readStore(key('minutes'), 60))
  watch(minutes, (v) => writeStore(key('minutes'), v))
  const save = () => writeStore(key('plan-done'), [...done.value])

  const steps = computed(() => {
    const articles = productsRef.value.filter((p) => !p.mystery || p.revealed)
    const list = [
      { key: 'grid-open', kind: 'mode', mode: 'grid', label: '🧩 Vitrine : tous les articles', say: 'Bonsoir à tous ! Voici tout ce que j\'ai pour vous ce soir.', time: '1 min', secs: 60 },
      { key: 'how-open', kind: 'mode', mode: 'how', label: '🛒 Comment commander', say: 'Pour commander, c\'est simple : regardez le code de l\'article et envoyez-le-moi au numéro de la bio.', time: '30 s', secs: 30 },
    ]
    articles.forEach((p, k) => {
      list.push({ key: `art-${p.code}`, kind: 'article', code: p.code, label: `📦 ${p.code} en grand · ${p.title}`, say: `Regardez la ${p.code} : la matière, les tailles, comment la porter.`, time: '3 min', secs: 180, skip: p.remaining === 0 })
      const n = k + 1
      if (n % 6 === 0 && n < articles.length) list.push({ key: `grid-${n}`, kind: 'mode', mode: 'grid', label: '🧩 Vitrine : ce qui reste', say: 'On fait le point : voici ce qu\'il reste !', time: '30 s', secs: 30 })
      else if (n % 3 === 0 && n < articles.length) list.push({ key: `how-${n}`, kind: 'mode', mode: 'how', label: '🛒 Rappel : comment commander', say: 'Pour ceux qui viennent d\'arriver : envoyez le code de l\'article au numéro de la bio.', time: '30 s', secs: 30 })
    })
    list.push({ key: 'scroll-end', kind: 'mode', mode: 'scroll', label: '🔁 Défilement : dernier tour', say: 'Dernier tour : voici tout ce qu\'il reste, c\'est le moment !', time: '2 min', secs: 120 })
    list.push({ key: 'grid-end', kind: 'mode', mode: 'grid', label: '🧩 Vitrine finale', say: 'Merci à tous ! Voici ce qu\'il reste, envoyez vite votre code.', time: '1 min', secs: 60 })
    return list.map((step) => ({ ...step, done: done.value.has(step.key) })).filter((step) => step.done || !step.skip)
  })
  const position = computed(() => {
    const at = steps.value.findIndex((step) => !step.done)
    return at < 0 ? steps.value.length : at
  })
  const next = computed(() => steps.value[position.value] ?? null)
  const following = computed(() => steps.value[position.value + 1] ?? null)
  function mark(keys) {
    const set = new Set(done.value)
    keys.forEach((k) => set.add(k))
    done.value = set
    save()
  }
  // L'étape à l'écran maintenant (la dernière lancée par le plan)
  const current = computed(() => (now.value ? steps.value.find((step) => step.key === now.value.key) ?? null : null))
  const currentIndex = computed(() => (current.value ? steps.value.indexOf(current.value) : -1))
  // Lancer une étape : elle devient « maintenant », les étapes d'avant sont faites
  function start(step) {
    const at = steps.value.findIndex((x) => x.key === step.key)
    mark(steps.value.slice(0, at + 1).map((x) => x.key))
    now.value = { key: step.key, at: Date.now() }
  }
  const advance = () => next.value && start(next.value)
  // Étape précédente : l'étape en cours redevient à faire, la précédente revient à l'écran
  function back() {
    const at = currentIndex.value
    if (at < 0) return null
    const set = new Set(done.value)
    set.delete(steps.value[at].key)
    done.value = set
    save()
    const prev = steps.value[at - 1] ?? null
    now.value = prev ? { key: prev.key, at: Date.now() } : null
    return prev
  }
  function restart() {
    done.value = new Set()
    save()
    now.value = null
  }
  // Article montré à la main : si c'est l'étape suivante du plan, on avance ; sinon on dit qu'on est sorti du plan
  function reached(code) {
    if (next.value?.kind === 'article' && next.value.code === code) {
      start(next.value)
      return true
    }
    return false
  }
  // « Continuer d'ici » : le plan se recale sur l'article montré
  function adopt(code) {
    const step = steps.value.find((x) => x.key === `art-${code}`)
    if (step) start(step)
    return !!step
  }
  const plannedEnd = computed(() => (liveRef.value.started_at ? new Date(liveRef.value.started_at).getTime() + minutes.value * 60000 : null))

  return { steps, position, next, following, current, currentIndex, now, start, advance, back, restart, reached, adopt, minutes, plannedEnd }
}

/** Journal des moments lancés depuis ce téléphone (pour ne pas reproposer le même moment trop tôt) */
export function useMomentLog(liveRef) {
  const key = `jm.live-moments.${liveRef.value.id}`
  const log = ref(readStore(key, []))
  const add = (type) => {
    log.value = [...log.value, { type, at: Date.now() }].slice(-200)
    writeStore(key, log.value)
  }
  const lastAt = (type) => Math.max(0, ...log.value.filter((m) => m.type === type).map((m) => m.at))
  return { log, add, lastAt }
}
