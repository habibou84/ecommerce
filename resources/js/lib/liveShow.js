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
 * Plan du live : les articles dans l'ordre, avec des moments placés pour garder le rythme (défi après le 2e article,
 * l'article mystère annoncé puis lancé au milieu, les 3 premiers, la carte, la dernière chance à la fin).
 *
 * @param liveRef   ref du live (id, items, started_at)
 * @param productsRef  produits du live (un par code, avec mystery / revealed)
 */
export function useLivePlan(liveRef, productsRef) {
  const key = (name) => `jm.live-${name}.${liveRef.value.id}`
  const index = ref(readStore(key('plan'), 0))
  const minutes = ref(readStore(key('minutes'), 60))
  watch(index, (v) => writeStore(key('plan'), v))
  watch(minutes, (v) => writeStore(key('minutes'), v))

  const steps = computed(() => {
    const products = productsRef.value
    const shown = products.filter((p) => !p.mystery || p.revealed)
    const mystery = products.find((p) => p.mystery && !p.revealed) ?? products.find((p) => p.mystery)
    const middle = Math.max(1, Math.floor(shown.length / 2))
    const list = []
    shown.forEach((p, k) => {
      list.push({ kind: 'article', code: p.code, id: p.id, label: `Montrer ${p.code} · ${p.title}` })
      if (k === 0 && mystery) list.push({ kind: 'moment', type: 'announce', label: '📣 Annoncer l\'article mystère' })
      if (k === 1) list.push({ kind: 'moment', type: 'defi', label: '🎯 Lancer le défi' })
      if (k === middle && mystery) list.push({ kind: 'moment', type: 'mystery', label: `🎁 Article mystère (${mystery.code})` })
      if (k === 2) list.push({ kind: 'moment', type: 'podium', label: '🥇 Les 3 premiers' })
    })
    if (shown.length < 2 && mystery) list.push({ kind: 'moment', type: 'mystery', label: `🎁 Article mystère (${mystery.code})` })
    list.push({ kind: 'moment', type: 'map', label: '🗺️ Carte du live' })
    list.push({ kind: 'moment', type: 'last', label: '⏳ Dernière chance' })
    return list
  })
  const next = computed(() => steps.value[index.value] ?? null)
  const advance = () => (index.value = Math.min(steps.value.length, index.value + 1))
  const plannedEnd = computed(() => (liveRef.value.started_at ? new Date(liveRef.value.started_at).getTime() + minutes.value * 60000 : null))

  return { steps, index, next, advance, minutes, plannedEnd }
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
