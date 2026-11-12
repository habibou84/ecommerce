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
  const minutes = ref(readStore(key('minutes'), 60))
  watch(minutes, (v) => writeStore(key('minutes'), v))
  const save = () => writeStore(key('plan-done'), [...done.value])

  const steps = computed(() => {
    const articles = productsRef.value.filter((p) => !p.mystery || p.revealed)
    const list = [
      { key: 'grid-open', kind: 'mode', mode: 'grid', label: '🧩 Vitrine : tous les articles', say: 'Bonsoir à tous ! Voici tout ce que j\'ai pour vous ce soir.', time: '1 min' },
      { key: 'how-open', kind: 'mode', mode: 'how', label: '🛒 Comment commander', say: 'Pour commander, c\'est simple : regardez le code de l\'article et envoyez-le-moi au numéro de la bio.', time: '30 s' },
    ]
    articles.forEach((p, k) => {
      list.push({ key: `art-${p.code}`, kind: 'article', code: p.code, label: `📦 ${p.code} en grand · ${p.title}`, say: `Regardez la ${p.code} : la matière, les tailles, comment la porter.`, time: '3 min', skip: p.remaining === 0 })
      const n = k + 1
      if (n % 6 === 0 && n < articles.length) list.push({ key: `grid-${n}`, kind: 'mode', mode: 'grid', label: '🧩 Vitrine : ce qui reste', say: 'On fait le point : voici ce qu\'il reste !', time: '30 s' })
      else if (n % 3 === 0 && n < articles.length) list.push({ key: `how-${n}`, kind: 'mode', mode: 'how', label: '🛒 Rappel : comment commander', say: 'Pour ceux qui viennent d\'arriver : envoyez le code de l\'article au numéro de la bio.', time: '30 s' })
    })
    list.push({ key: 'scroll-end', kind: 'mode', mode: 'scroll', label: '🔁 Défilement : dernier tour', say: 'Dernier tour : voici tout ce qu\'il reste, c\'est le moment !', time: '2 min' })
    list.push({ key: 'grid-end', kind: 'mode', mode: 'grid', label: '🧩 Vitrine finale', say: 'Merci à tous ! Voici ce qu\'il reste, envoyez vite votre code.', time: '1 min' })
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
  const advance = () => next.value && mark([next.value.key])
  // Article montré à la main : son étape et celles d'avant sont faites
  function reached(code) {
    const at = steps.value.findIndex((step) => step.key === `art-${code}`)
    if (at >= 0 && !steps.value[at].done) mark(steps.value.slice(0, at + 1).map((step) => step.key))
  }
  const plannedEnd = computed(() => (liveRef.value.started_at ? new Date(liveRef.value.started_at).getTime() + minutes.value * 60000 : null))

  return { steps, position, next, following, advance, reached, minutes, plannedEnd }
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
