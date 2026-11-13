<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { boost, onBeat, play, setAmbiance, setMusic, setMuted } from '../lib/liveSounds'

/**
 * Écran du live en « show » (télé, ordinateur, TikTok LIVE Studio par le lien direct) : la mise en page de la démo
 * validée par la boutique. L'article montré en grand (photo, code qui tombe, tailles, « Envoie A1 + ta taille »,
 * barre de pièces), les commandes fêtées (ding, confettis, carte, « N commandes d'affilée »), l'urgence, ÉPUISÉ,
 * et en plein écran les moments lancés depuis le téléphone (3-2-1, vente flash, objectif, quartiers, roue,
 * comment commander). Règles de TikTok : ni prix, ni QR code, ni adresse de site, ni nom de messagerie.
 * Tout est calculé ici à partir de ce que l'écran reçoit déjà toutes les 2 secondes.
 */
const props = defineProps({
  data: { type: Object, default: null }, // { store, live }
})

const live = computed(() => props.data?.live ?? null)
const store = computed(() => props.data?.store ?? null)
const items = computed(() => live.value?.items ?? [])
const available = computed(() => items.value.filter((i) => i.remaining > 0))
const show = computed(() => live.value?.show ?? null)
const settings = computed(() => show.value?.settings ?? { theme: 'fete', ambiance: 'aqui', sound: true, music: false, light: false, camera: true, goal: null })
const light = computed(() => !!settings.value.light)

// Taille : tout en « em ». Large (télé) : 1em = 1/128 de la largeur ; carré (TikTok LIVE Studio) : 1/84 ;
// vertical (télé tournée, tablette debout) : 1/72 de la largeur, la mise en page remplit toute la hauteur.
const root = ref(null)
const layout = ref('wide') // 'wide' | 'square' | 'tall'
const square = computed(() => layout.value !== 'wide')
const unit = ref(10)
let observer
onMounted(() => {
  observer = new ResizeObserver(([entry]) => {
    const { width, height } = entry.contentRect
    layout.value = width >= height * 1.25 ? 'wide' : width < height * 0.8 ? 'tall' : 'square'
    unit.value = layout.value === 'wide' ? Math.min(width / 128, height / 72) : layout.value === 'tall' ? Math.min(width / 72, height / 128) : Math.min(width / 84, height / 84)
  })
  observer.observe(root.value)
  // Les lumières de la scène battent avec la musique
  onBeat(() => {
    const el = root.value
    if (!el || light.value) return
    el.classList.remove('beat')
    void el.offsetWidth
    el.classList.add('beat')
  })
})

// Article montré (défilement : un article disponible toutes les 8 secondes)
const step = ref(0)
const rotate = setInterval(() => (step.value += 1), 8000)
const mode = computed(() => live.value?.mode ?? 'product')
const shown = computed(() => {
  if (!live.value) return null
  if (mode.value === 'scroll') {
    const list = available.value.length ? available.value : items.value
    return list.length ? list[step.value % list.length] : null
  }
  return items.value.find((i) => i.id === live.value.featured_item_id) ?? available.value[0] ?? items.value[0] ?? null
})
const sizes = computed(() => shown.value?.options?.[0]?.values ?? [])
const choices = computed(() => !!shown.value?.choices)
const stockPct = computed(() => (shown.value ? Math.max(0, Math.min(100, (shown.value.remaining / Math.max(1, shown.value.quantity)) * 100)) : 0))
const stockText = computed(() => {
  const n = shown.value?.remaining ?? 0
  return n <= 0 ? 'Épuisé' : n <= 2 ? `🔥 Plus que ${n} !` : `${n} pièces`
})
const phoneLine = computed(() => (store.value?.phone_shown ? `📱 ${store.value.phone_shown}` : '📱 Numéro dans la bio'))
const gridItems = computed(() => items.value.slice(0, layout.value === 'tall' ? 9 : 10))
// Vitrine : vignettes aussi grandes que possible selon le nombre d'articles et la forme de l'écran
const gridCols = computed(() => {
  const n = gridItems.value.length
  if (layout.value === 'tall') return n <= 4 ? 2 : 3
  if (layout.value === 'square') return n <= 4 ? 2 : 3
  return Math.max(3, Math.min(5, Math.ceil(n / 2)))
})

// ── Photos : jusqu'à 3 par article, chacune en entier puis en gros plan (toutes les 3,6 secondes) ──
const views = computed(() => {
  const photos = shown.value?.photos?.length ? shown.value.photos : shown.value?.image ? [shown.value.image] : []
  return photos.flatMap((src, k) => [{ src, k, zoom: false }, { src, k, zoom: true }])
})
const photoList = computed(() => [...new Set(views.value.map((v) => v.src))])
const viewAt = ref(0)
const viewTimer = setInterval(() => (viewAt.value += 1), 3600)
watch(() => shown.value?.id, () => (viewAt.value = 0))
const view = computed(() => (views.value.length ? views.value[viewAt.value % views.value.length] : null))

// ── Sons ──
watch([() => settings.value.sound, () => settings.value.music, () => settings.value.ambiance], () => {
  setMuted(!settings.value.sound)
  setAmbiance(settings.value.ambiance)
  setMusic(!!settings.value.music && !!settings.value.sound)
}, { immediate: true })

// ── Article qui arrive : projecteur, code qui tombe, souffle puis impact ──
const spotKey = ref(0)
watch(() => shown.value?.id, (id, before) => {
  if (id === undefined || before === undefined || id === before) return
  spotKey.value += 1
  play('show')
})

// ── Commandes : ding, confettis, cartes (3 au plus), « N commandes d'affilée » ──
const toasts = ref([])
const combo = ref(0)
let comboTimes = []
let comboTimer
watch(() => (live.value ? live.value.ticker?.[0]?.id ?? 0 : undefined), (id, before) => {
  if (id === undefined || before === undefined || id <= before) return
  const fresh = (live.value.ticker ?? []).filter((t) => t.id > before).reverse()
  fresh.forEach((t, i) => setTimeout(() => {
    toasts.value = [{ id: t.id, text: t.text }, ...toasts.value].slice(0, 3)
    setTimeout(() => (toasts.value = toasts.value.filter((x) => x.id !== t.id)), 3400)
    play('order')
    burst('confetti', layout.value === 'wide' ? 0.78 : 0.5, layout.value === 'tall' ? 0.3 : 0.75)
  }, i * 600))
  const now = Date.now()
  comboTimes = [...comboTimes.filter((x) => now - x < 60000), ...Array(Math.max(1, fresh.length)).fill(now)]
  combo.value = comboTimes.length
  if (combo.value >= 3) boost(15) // la musique monte d'un cran quand les commandes s'enchaînent
  clearTimeout(comboTimer)
  comboTimer = setTimeout(() => (combo.value = 0), 6000)
})
const tickerText = computed(() => {
  const list = (live.value?.ticker ?? []).map((t) => `🎉 ${t.text}`)
  const quarters = (show.value?.quarters ?? []).slice(0, 5).map((q) => `📍 ${q.name} : ${q.orders} commande${q.orders > 1 ? 's' : ''}`)
  return [`🔴 ${live.value?.orders ?? 0} commande${(live.value?.orders ?? 0) > 1 ? 's' : ''} pendant ce live`, ...list, ...quarters, '✨ Nouveaux articles toute la soirée', phoneLine.value]
})

// ── Urgence et épuisé ──
const soldKey = ref(0)
watch(() => [shown.value?.id, shown.value?.remaining], ([id, n], [beforeId, was]) => {
  if (id !== beforeId || n === undefined || was === undefined || n >= was) return
  if (n === 0) { soldKey.value += 1; play('stamp') } else if (n <= 2) { play('beat'); setTimeout(() => play('beat'), 700) }
})

// ── Vente flash : plein écran, chrono, tic-tac, sirène ──
const nowMs = ref(Date.now())
const second = setInterval(() => (nowMs.value = Date.now()), 1000)
const flashItem = computed(() => items.value.find((i) => i.flash_ends_at && new Date(i.flash_ends_at).getTime() > nowMs.value) ?? null)
const flashLeft = computed(() => (flashItem.value ? Math.max(0, Math.round((new Date(flashItem.value.flash_ends_at).getTime() - nowMs.value) / 1000)) : 0))
const flashClock = computed(() => `${Math.floor(flashLeft.value / 60)}:${String(flashLeft.value % 60).padStart(2, '0')}`)
watch(flashLeft, (n, was) => {
  if (was === 0 && n > 0) { play('flash'); boost(Math.min(n, 120)) }
  else if (n > 0 && n <= 10 && n < was) play('tick')
  else if (n === 0 && was > 0) play('buzzer')
})

// ── Moments lancés depuis le téléphone (une fois, dans les 20 secondes) ──
const moment = ref(null)
const countN = ref(3)
const goalFill = ref(0)
const wheelCanvas = ref(null)
const wheelTurn = ref(0)
const wheelDone = ref(false)
let seen = null
let timers = []
const later = (fn, ms) => timers.push(setTimeout(fn, ms))
const clearTimers = () => { timers.forEach(clearTimeout); timers = [] }
function finish(ms) { later(() => (moment.value = null), ms) }
function start(event) {
  // Moments qui ne prennent pas tout l'écran, ou qui suivent l'état du show (défi, mystère, fin du live…)
  const quick = {
    react: () => react(event.data.emoji),
    question: () => ask(event.data),
    podium: () => startPodium(event.data),
    map: startMap,
    how: () => { interlude.value = true; lastInterlude = Date.now(); play('whoosh'); setTimeout(() => (interlude.value = false), 7000) },
    defi: () => play('whoosh'),
    defi_stop: () => {},
    last: () => { play('buzzer'); boost(30) },
    announce: () => play('whoosh'),
    mystery: () => { play('show'); boost(30) },
    hint: () => { play('pop'); setTimeout(() => play('ding'), 150) },
    reveal: () => { whiteKey.value += 1; play('goal'); boost(10); burst('confetti', 0.5, 0.45) },
  }
  if (quick[event.type]) {
    quick[event.type]()
    return
  }
  clearTimers()
  moment.value = { ...event }
  if (event.type === 'countdown') {
    countN.value = 3
    play('count')
    later(() => { countN.value = 2; play('count') }, 900)
    later(() => { countN.value = 1; play('count') }, 1800)
    later(() => { play('go'); moment.value = null; spotKey.value += 1; play('impact') }, 2700)
  } else if (event.type === 'party') {
    moment.value = null
    play('goal')
    boost(10)
    burst('confetti', 0.5, 0.4)
    later(() => burst('confetti', 0.3, 0.5), 300)
    later(() => burst('confetti', 0.7, 0.5), 600)
  } else if (event.type === 'goal') {
    goalFill.value = 0
    play('whoosh')
    later(() => (goalFill.value = Math.min(100, ((live.value?.orders ?? 0) / Math.max(1, event.data.goal)) * 100)), 200)
    if ((live.value?.orders ?? 0) >= event.data.goal) {
      later(() => { play('goal'); boost(10); burst('firework', 0.3, 0.35) }, 2200)
      later(() => burst('firework', 0.7, 0.3), 2550)
      later(() => burst('firework', 0.5, 0.2), 2900)
    }
    finish(8000)
  } else if (event.type === 'match') {
    play('whoosh')
    later(() => play('pop'), 900)
    finish(8000)
  } else if (event.type === 'wheel') {
    wheelTurn.value = 0
    wheelDone.value = false
    nextTick(drawWheel)
    const slots = event.data.slots.length
    later(() => (wheelTurn.value = 6 * 360 + (360 - (event.data.winner + 0.5) * (360 / slots))), 100)
    let delay = 40
    let total = 0
    const clack = () => { play('wheel'); total += delay; delay *= 1.09; if (total < 5000) later(clack, delay) }
    clack()
    later(() => { wheelDone.value = true; play('goal'); burst('confetti', 0.5, 0.5) }, 5400)
    finish(12000)
  }
}
const WHEEL = ['#ff3d6e', '#ffb21e', '#2ee59d', '#38bdf8', '#a78bfa', '#fb923c']
function drawWheel() {
  const c = wheelCanvas.value
  const slots = moment.value?.data?.slots
  if (!c || !slots) return
  const g = c.getContext('2d')
  const seg = (Math.PI * 2) / slots.length
  g.clearRect(0, 0, 600, 600)
  slots.forEach((label, i) => {
    g.beginPath(); g.moveTo(300, 300); g.arc(300, 300, 290, i * seg - Math.PI / 2, (i + 1) * seg - Math.PI / 2); g.closePath()
    g.fillStyle = WHEEL[i % WHEEL.length]; g.fill(); g.strokeStyle = 'rgba(0,0,0,.25)'; g.lineWidth = 4; g.stroke()
    g.save(); g.translate(300, 300); g.rotate((i + 0.5) * seg - Math.PI / 2); g.textAlign = 'right'; g.fillStyle = '#1a0d24'
    g.font = `800 ${slots.length > 8 ? 22 : 28}px Inter, system-ui, sans-serif`; g.fillText(label, 270, 10); g.restore()
  })
}
const wheelWinner = computed(() => (moment.value?.type === 'wheel' ? moment.value.data.slots[moment.value.data.winner] : ''))
const quarterRows = computed(() => {
  const rows = (show.value?.quarters ?? []).slice(0, 4)
  const max = Math.max(1, ...rows.map((r) => r.orders))
  return rows.map((r) => ({ ...r, width: `${Math.round((r.orders / max) * 100)}%` }))
})

// ── « Comment commander » : choisi sur le téléphone, ou 7 secondes toutes les 75 secondes entre deux articles ──
const interlude = ref(false)
let lastInterlude = Date.now()
const interTimer = setInterval(() => {
  if (moment.value || flashLeft.value > 0 || mode.value !== 'product' && mode.value !== 'scroll') return
  if (Date.now() - lastInterlude < 75000) return
  lastInterlude = Date.now()
  interlude.value = true
  play('whoosh')
  setTimeout(() => (interlude.value = false), 7000)
}, 5000)
// ═══ Live interactif ═══
const state = computed(() => show.value?.state ?? {})

// Réactions : pluie d'émojis depuis le bas (calculée par l'écran)
const rain = ref([])
let rainId = 0
function react(e) {
  const drops = Array.from({ length: light.value ? 8 : 22 }, () => ({ id: ++rainId, e, left: 5 + Math.random() * 90, s: 3 + Math.random() * 4, d: 2.2 + Math.random() * 1.8, dx: Math.random() * 16 - 8, r: Math.random() * 40 - 20, delay: Math.random() * 0.8 }))
  rain.value = [...rain.value, ...drops].slice(-60)
  const ids = new Set(drops.map((d) => d.id))
  setTimeout(() => (rain.value = rain.value.filter((d) => !ids.has(d.id))), 5200)
  play('pop')
}

// Question du public : le texte et la commune, jamais le nom ni le numéro
const bubble = ref(null)
let bubbleTimer
function ask(data) {
  bubble.value = { ...data, key: Date.now() }
  play('whoosh')
  setTimeout(() => play('ding'), 300)
  clearTimeout(bubbleTimer)
  bubbleTimer = setTimeout(() => (bubble.value = null), 9000)
}

// Les 3 premiers à commander l'article : podium rempli avec les communes des commandes qui arrivent
const podium = ref(null)
let podiumTimer
function startPodium(data) {
  podium.value = { ...data, who: [] }
  play('fanfare')
  boost(20)
  clearTimeout(podiumTimer)
  podiumTimer = setTimeout(() => (podium.value = null), 90000)
}
// Carte du live : les communes des commandes, une onde à chaque nouvelle commande
const mapOn = ref(false)
const ripples = ref([])
let mapTimer
function startMap() {
  mapOn.value = true
  play('whoosh')
  clearTimeout(mapTimer)
  mapTimer = setTimeout(() => (mapOn.value = false), 14000)
}
// Carte simplifiée d'Abidjan : position des communes (en % du cadre)
const MAP = { Anyama: [50, 6], Abobo: [52, 17], Adjamé: [46, 33], Attécoubé: [35, 38], Plateau: [51, 48], Cocody: [68, 37], Bingerville: [88, 34], Yopougon: [19, 40], Songon: [5, 45], Treichville: [48, 66], Marcory: [62, 70], Koumassi: [77, 72], 'Port-Bouët': [64, 88] }
const pins = computed(() => {
  const rows = show.value?.quarters ?? []
  const max = Math.max(1, ...rows.map((r) => r.orders))
  return Object.entries(MAP).map(([name, [x, y]]) => {
    const n = rows.find((r) => r.name === name)?.orders ?? 0
    return { name, x, y, n, r: 1.4 + Math.sqrt(n) * 1.6, top: n > 0 && n === max }
  })
})
const otherPlaces = computed(() => (show.value?.quarters ?? []).filter((r) => !MAP[r.name]))
// Nouvelles commandes : podium et carte
watch(() => live.value?.ticker?.[0]?.id ?? 0, () => {
  const ticker = live.value?.ticker ?? []
  const p = podium.value
  if (p && p.who.length < 3) {
    const fresh = ticker.filter((t) => t.id > p.after && t.code === p.code && !p.who.some((w) => w.id === t.id)).sort((a, b) => a.id - b.id)
    for (const t of fresh.slice(0, 3 - p.who.length)) p.who.push({ id: t.id, place: t.commune || 'Une cliente' })
    if (p.who.length === 3) {
      setTimeout(() => { play('goal'); burst('firework', 0.5, 0.3) }, 500)
      clearTimeout(podiumTimer)
      podiumTimer = setTimeout(() => (podium.value = null), 5000)
    }
  }
  if (mapOn.value) {
    for (const t of ticker.slice(0, 3)) {
      const pos = MAP[t.commune]
      if (pos && !ripples.value.some((r) => r.id === t.id)) {
        ripples.value.push({ id: t.id, x: pos[0], y: pos[1] })
        setTimeout(() => (ripples.value = ripples.value.filter((r) => r.id !== t.id)), 1300)
      }
    }
  }
})

// Défi du live : barre en haut, puis « Défi réussi ! » une fois (gardé si l'écran se recharge)
const defi = computed(() => state.value.defi ?? null)
const celebrated = ref((() => {
  try {
    return Number(sessionStorage.getItem('jm.defi-done')) || 0
  } catch {
    return 0
  }
})())
const unlock = ref(false)
const defiOn = computed(() => !!defi.value && celebrated.value !== defi.value.id)
const defiLeft = computed(() => (defi.value ? Math.max(0, defi.value.target - (live.value?.orders ?? 0)) : 0))
const defiFill = computed(() => (defi.value ? Math.min(100, (((live.value?.orders ?? 0) - defi.value.from) / Math.max(1, defi.value.target - defi.value.from)) * 100) : 0))
watch(() => [defi.value?.id, live.value?.orders], () => {
  if (!defiOn.value || defiLeft.value > 0) return
  celebrated.value = defi.value.id
  try {
    sessionStorage.setItem('jm.defi-done', String(defi.value.id))
  } catch {
    // sans stockage : la fête ne revient qu'au rechargement
  }
  setTimeout(() => {
    unlock.value = true
    play('goal')
    boost(10)
    burst('firework', 0.3, 0.3)
    setTimeout(() => burst('firework', 0.7, 0.35), 300)
    setTimeout(() => burst('confetti', 0.5, 0.5), 600)
    setTimeout(() => (unlock.value = false), 5500)
  }, 700)
})

// Dernière chance : bord rouge qui bat et compte à rebours, puis « Merci ! »
const lastLeft = computed(() => (state.value.last_until ? Math.max(0, Math.round((new Date(state.value.last_until).getTime() - nowMs.value) / 1000)) : 0))
const bye = ref(false)
watch(lastLeft, (n, was) => {
  if (n > 0 && n % 4 === 0) play('beat')
  if (n === 0 && was > 0) {
    bye.value = true
    play('fanfare')
    burst('confetti', 0.5, 0.4)
    setTimeout(() => (bye.value = false), 10000)
  }
})
const clock = (sec) => `${Math.floor(sec / 60)}:${String(sec % 60).padStart(2, '0')}`

// Article mystère : annoncé à l'avance, puis deviné indice par indice (boîte, gros plan qui recule, flou qui se précise)
const mystery = computed(() => state.value.mystery ?? null)
const annLeft = computed(() => (state.value.announce_at && !mystery.value ? Math.max(0, Math.round((new Date(state.value.announce_at).getTime() - nowMs.value) / 1000)) : 0))
const mysteryMode = computed(() => settings.value.mystery_mode || 'zoom')
const mysteryStyle = computed(() => {
  const m = mystery.value
  if (!m) return {}
  const level = Math.min(3, m.level)
  if (mysteryMode.value === 'zoom') return { transformOrigin: `${m.focus?.[0] ?? 50}% ${m.focus?.[1] ?? 60}%`, transform: `scale(${[5, 3.4, 2.2, 1.5][level]})` }
  return { transform: 'scale(1.1)', filter: `blur(calc(var(--u) * ${[3.2, 2, 1.1, 0.5][level]})) saturate(1.3)` }
})
watch(nowMs, () => mystery.value && play('wheel'))
const whiteKey = ref(0)

// Grand moment ou vente flash : l'écran entier, sans l'article derrière
const fullscreen = computed(() => (!!moment.value && moment.value.type !== 'party') || !!flashItem.value || !!podium.value || mapOn.value || !!mystery.value || unlock.value || bye.value)
const showHow = computed(() => !live.value || mode.value === 'how' || interlude.value || !shown.value)

// ── Confettis et feux d'artifice (canvas, seulement pendant l'effet) ──
const fx = ref(null)
let parts = []
let raf = null
const COLORS = { fete: ['#ff3d6e', '#ffb21e', '#2ee59d', '#fff7ee'], wax: ['#ff7a00', '#ffd23f', '#00c2a8', '#fff7ee'], neon: ['#00e5ff', '#ff2bd6', '#9dff3c', '#ffffff'], luxe: ['#d9b26a', '#f3e2b3', '#ffffff', '#b08d57'] }
function burst(kind, x, y) {
  const cv = fx.value
  if (!cv) return
  const dpr = Math.min(2, window.devicePixelRatio || 1)
  cv.width = cv.clientWidth * dpr
  cv.height = cv.clientHeight * dpr
  const n = light.value ? 30 : kind === 'firework' ? 90 : 120
  const cs = COLORS[settings.value.theme] ?? COLORS.fete
  for (let i = 0; i < n; i++) {
    const a = Math.random() * Math.PI * 2
    const s = (kind === 'firework' ? 6 : 9) * (0.4 + Math.random()) * dpr
    parts.push({ x: x * cv.width, y: y * cv.height, vx: Math.cos(a) * s, vy: Math.sin(a) * s - (kind === 'confetti' ? 6 * dpr : 0), life: 1, c: cs[i % cs.length], r: (kind === 'firework' ? 2.2 : 4) * dpr, rot: Math.random() * 6, k: kind, dpr })
  }
  if (!raf) raf = requestAnimationFrame(draw)
}
function draw() {
  const cv = fx.value
  if (!cv) { raf = null; return }
  const cx = cv.getContext('2d')
  cx.clearRect(0, 0, cv.width, cv.height)
  parts = parts.filter((p) => p.life > 0)
  for (const p of parts) {
    p.vy += 0.25 * p.dpr; p.vx *= 0.985; p.x += p.vx; p.y += p.vy; p.life -= p.k === 'firework' ? 0.018 : 0.01; p.rot += 0.2
    cx.globalAlpha = Math.max(p.life, 0)
    cx.fillStyle = p.c
    if (p.k === 'firework') { cx.beginPath(); cx.arc(p.x, p.y, p.r, 0, 7); cx.fill() } else { cx.save(); cx.translate(p.x, p.y); cx.rotate(p.rot); cx.fillRect(-p.r, -p.r / 2, p.r * 2, p.r); cx.restore() }
  }
  raf = parts.length ? requestAnimationFrame(draw) : (cx.clearRect(0, 0, cv.width, cv.height), null)
}

// Placé après tout le reste : un moment déjà en cours au chargement utilise des valeurs déclarées plus haut
watch(() => show.value?.event?.id, (id) => {
  if (!id) return
  if (seen === null) { seen = id; if (Date.now() - id > 20000) return } else if (id <= seen) return
  seen = id
  if (Date.now() - id > 20000) return
  start(show.value.event)
}, { immediate: true })

onUnmounted(() => {
  observer?.disconnect()
  clearInterval(rotate)
  clearInterval(second)
  clearInterval(interTimer)
  clearInterval(viewTimer)
  onBeat(null)
  clearTimeout(comboTimer)
  clearTimeout(bubbleTimer)
  clearTimeout(podiumTimer)
  clearTimeout(mapTimer)
  clearTimers()
  cancelAnimationFrame(raf)
  setMusic(false)
})
</script>

<template>
  <div ref="root" class="show" :class="[`th-${settings.theme}`, { 'is-square': square, 'is-tall': layout === 'tall', 'is-light': light, 'is-camera': !!settings.camera }]" :style="{ '--u': `${unit}px` }">
    <div class="orb" aria-hidden="true" /><div class="orb b" aria-hidden="true" />
    <div class="beam l" aria-hidden="true" /><div class="beam r" aria-hidden="true" /><div class="flood" aria-hidden="true" />
    <div class="brand"><span class="live-dot"><i />LIVE</span><b>{{ store?.name }}</b><span v-if="settings.goal && !moment" class="goal-mini">🎯 {{ live?.orders ?? 0 }} / {{ settings.goal }}</span></div>
    <div class="bio">{{ phoneLine }}</div>

    <!-- Article montré -->
    <!-- Pas de :key sur ce bloc (il fait partie d'une suite v-if / v-else-if : Vue plantait en passant au plein
         écran d'un moment) ; la clé est sur la photo et le texte pour rejouer leur arrivée -->
    <div v-if="shown && !showHow && mode !== 'grid' && !fullscreen" class="product">
      <div :key="`p${shown.id}`" class="photo">
        <template v-if="view">
          <div class="photo-bg" :style="{ backgroundImage: `url('${view.src}')` }" />
          <img v-for="src in photoList" :key="src" :src="src" alt="" class="pic" :class="{ on: src === view.src, zoom: src === view.src && view.zoom }">
          <div class="shine" />
          <div v-if="views.length > 2" class="dots"><i v-for="(src, k) in photoList" :key="src" :class="{ on: k === view.k }" /></div>
        </template>
        <span v-else class="no-photo">{{ shown.code }}</span>
      </div>
      <div :key="`i${shown.id}`" class="info">
        <div class="code">{{ shown.code }}</div>
        <div class="title">{{ shown.title }}</div>
        <div v-if="sizes.length" class="sizes">
          <span v-for="v in sizes" :key="v.value" :class="{ out: v.remaining === 0 }">{{ v.value }}</span>
        </div>
        <div class="how">Envoie <b>{{ shown.code }}{{ choices ? ' + ta taille' : '' }}</b> au numéro de la bio</div>
        <div class="stock" :class="{ low: shown.remaining > 0 && shown.remaining <= 2 }">
          <div class="bar"><i :style="{ width: `${stockPct}%` }" /></div>
          <p>{{ stockText }}</p>
        </div>
      </div>
      <template v-if="shown.remaining === 0">
        <div :key="`st${soldKey}`" class="stamp">ÉPUISÉ</div>
        <div :key="`ss${soldKey}`" class="sub-stamp">📝 Liste d'attente ouverte : envoie quand même le code</div>
      </template>
    </div>

    <!-- Vitrine : 10 articles et leur code -->
    <div v-else-if="fullscreen" />
    <div v-else-if="mode === 'grid' && items.length && !interlude" class="grid">
      <h2>En direct maintenant</h2>
      <div class="cells" :style="{ gridTemplateColumns: `repeat(${gridCols}, 1fr)` }">
        <div v-for="i in gridItems" :key="i.id" class="cell" :class="{ out: i.remaining === 0 }">
          <img v-if="i.image" :src="i.image" alt="">
          <b>{{ i.code }}</b>
        </div>
      </div>
      <p class="kicker">Envoie le code de l'article · {{ phoneLine }}</p>
    </div>

    <!-- Comment commander -->
    <div v-else class="overlay soft">
      <div class="inter">
        <h2>{{ live ? 'Comment commander ?' : 'Le live commence bientôt' }}</h2>
        <div class="steps">
          <div><b>1</b>Regarde le code de l'article</div>
          <div><b>2</b>Envoie le code{{ choices ? ' + ta taille' : '' }}</div>
          <div><b>3</b>On te demande ton quartier</div>
        </div>
        <p class="kicker">{{ phoneLine }}</p>
      </div>
    </div>

    <div v-if="spotKey" :key="`spot${spotKey}`" class="spot" aria-hidden="true" />
    <div v-if="combo >= 2 && !fullscreen" :key="`c${combo}`" class="combo">🔥 {{ combo }} commandes d'affilée !</div>
    <div class="toasts" :class="{ low: fullscreen }">
      <div v-for="t in toasts" :key="t.id" class="toast">🎉 {{ t.text }}</div>
    </div>

    <!-- Défi en cours, article mystère annoncé, dernière chance -->
    <div v-if="defiOn && !unlock" class="defi">
      <p>🎯 Défi : encore {{ defiLeft }} commande{{ defiLeft > 1 ? 's' : '' }} → 🎁 {{ defi.reward }}</p>
      <div class="dbar"><i :style="{ width: `${defiFill}%` }" /></div>
    </div>
    <div v-if="annLeft > 0" class="ann">🎁 Article mystère dans {{ clock(annLeft) }}</div>
    <template v-if="lastLeft > 0">
      <div class="lastv" aria-hidden="true" />
      <div class="lastb">⏳ Dernière chance · {{ clock(lastLeft) }}</div>
    </template>
    <!-- Question du public -->
    <div v-if="bubble" :key="bubble.key" class="bubble">
      <small>💬 {{ bubble.place ? `QUESTION DE ${bubble.place.toUpperCase()}` : 'QUESTION D\'UNE CLIENTE' }}</small>
      <p>« {{ bubble.text }} »</p>
      <span class="mic">🎙️ Je réponds en direct</span>
    </div>
    <!-- Réactions -->
    <div class="rain" aria-hidden="true">
      <span v-for="d in rain" :key="d.id" class="drop" :style="{ left: `${d.left}%`, '--s': `${d.s}em`, '--d': `${d.d}s`, '--dx': `${d.dx}em`, '--r': `${d.r}deg`, animationDelay: `${d.delay}s` }">{{ d.e }}</span>
    </div>
    <div class="ticker"><div :key="tickerText.join()"><span v-for="(t, i) in tickerText" :key="i">{{ t }}</span></div></div>

    <!-- Vente flash -->
    <div v-if="flashItem && !moment" class="overlay">
      <div class="flash">
        <!-- La photo de l'article en vente flash, à côté du chrono (au-dessus en carré et en vertical) -->
        <div v-if="flashItem.image" class="flash-photo"><img :src="flashItem.image" alt=""><b>{{ flashItem.code }}</b></div>
        <div class="flash-text">
          <h2>⚡ VENTE FLASH</h2>
          <p class="kicker">sur la {{ flashItem.code }}, pendant</p>
          <div class="clock" :class="{ end: flashLeft <= 3 }">{{ flashClock }}</div>
          <p class="kicker">Envoie {{ flashItem.code }} maintenant !</p>
        </div>
      </div>
    </div>

    <!-- Moments lancés depuis le téléphone -->
    <div v-if="moment" class="overlay">
      <div v-if="moment.type === 'countdown'">
        <p class="kicker">Article vedette dans…</p>
        <div :key="countN" class="count">{{ countN }}</div>
      </div>
      <div v-else-if="moment.type === 'goal'" class="goal">
        <p class="kicker">Objectif du live</p>
        <h2>🎯 {{ moment.data.goal }} commandes</h2>
        <div class="gbar"><i :style="{ width: `${goalFill}%` }" /></div>
        <div class="num">{{ live?.orders ?? 0 }} <small>/ {{ moment.data.goal }}{{ (live?.orders ?? 0) >= moment.data.goal ? ' · objectif atteint !' : '' }}</small></div>
      </div>
      <div v-else-if="moment.type === 'match'" class="match">
        <p class="kicker">Quel quartier va gagner ?</p>
        <h2>🏆 Le match des quartiers</h2>
        <p v-if="!quarterRows.length" class="kicker">Envoie ton code pour faire gagner ton quartier !</p>
        <div v-for="r in quarterRows" :key="r.name" class="row"><span>{{ r.name }}</span><div class="track"><i :style="{ width: r.width }" /></div><b>{{ r.orders }}</b></div>
      </div>
      <div v-else-if="moment.type === 'wheel'" class="wheelbox">
        <p class="kicker">Parmi les acheteurs du live</p>
        <h2>🎡 La roue de la chance</h2>
        <div class="wheel"><canvas ref="wheelCanvas" width="600" height="600" :style="{ transform: `rotate(${wheelTurn}deg)` }" /></div>
        <div class="winner-line"><p v-if="wheelDone" class="winner">🎁 {{ wheelWinner }} gagne la livraison offerte !</p></div>
      </div>
    </div>

    <!-- Article mystère -->
    <div v-if="mystery" class="overlay">
      <div class="mystery">
        <p class="kicker">Devine dans les commentaires !</p>
        <h2>ARTICLE MYSTÈRE</h2>
        <div class="mbox">
          <div v-if="mysteryMode === 'box' || !mystery.photo" class="gbox">🎁</div>
          <img v-else :src="mystery.photo" alt="" :style="mysteryStyle">
        </div>
        <div class="mhint">
          <template v-if="mystery.hints.length"><span v-for="h in mystery.hints" :key="h">💡 {{ h }}</span></template>
          <template v-else>Premier indice dans un instant…</template>
        </div>
      </div>
    </div>
    <!-- Les 3 premiers -->
    <div v-else-if="podium" class="overlay">
      <div class="podium">
        <p class="kicker">Les 3 premiers à commander la {{ podium.code }} gagnent un cadeau</p>
        <h2>🥇 Qui sera le premier ?</h2>
        <div class="steps3">
          <div v-for="rank in [2, 1, 3]" :key="rank" class="step3">
            <div :key="podium.who[rank - 1]?.id ?? `e${rank}`" class="who" :class="{ in: podium.who[rank - 1] }">{{ podium.who[rank - 1] ? `${['🥇', '🥈', '🥉'][rank - 1]} ${podium.who[rank - 1].place}` : '…' }}</div>
            <div class="block">{{ rank }}</div>
          </div>
        </div>
        <p class="kicker">Envoie {{ podium.code }} maintenant !</p>
      </div>
    </div>
    <!-- Carte du live -->
    <div v-else-if="mapOn" class="overlay">
      <div class="mapwrap">
        <h2 class="maptitle">🗺️ D'où viennent les commandes ?</h2>
        <div class="map">
          <div class="lagoon" />
          <div v-for="p in pins" :key="p.name" class="pin" :class="{ top: p.top }" :style="{ left: `${p.x}%`, top: `${p.y}%`, '--r': `${p.r}em` }"><i /><b>{{ p.name }}{{ p.n ? ` · ${p.n}` : '' }}</b></div>
          <div v-for="r in ripples" :key="r.id" class="ripple" :style="{ left: `${r.x}%`, top: `${r.y}%` }" />
        </div>
        <p class="kicker">Carte simplifiée d'Abidjan<template v-if="otherPlaces.length"> · {{ otherPlaces.map((o) => `${o.name} : ${o.orders}`).join(' · ') }}</template></p>
      </div>
    </div>
    <!-- Défi réussi -->
    <div v-else-if="unlock" class="overlay">
      <div class="rays2" aria-hidden="true" />
      <div class="unlock"><div class="gift">🎁</div><h2>DÉFI RÉUSSI !</h2><p>{{ defi?.reward }}</p></div>
    </div>
    <!-- Fin du live -->
    <div v-else-if="bye" class="overlay">
      <div class="bye"><h2>Merci ! 🙏</h2><p>Le live est terminé · à très vite</p></div>
    </div>
    <div v-if="whiteKey" :key="`w${whiteKey}`" class="whiteout" aria-hidden="true" />

    <canvas ref="fx" class="fx" aria-hidden="true" />
  </div>
</template>

<style scoped>
.show {
  --stage: #12061f; --stage2: #2a0b3d; --hot: #ff3d6e; --sun: #ffb21e; --mint: #2ee59d; --ink: #fff7ee; --dim: #c9b6d9;
  position: absolute; inset: 0; overflow: hidden; color: var(--ink); font-size: var(--u); font-family: 'Inter', system-ui, sans-serif;
  background: radial-gradient(ellipse 80% 70% at 30% 20%, var(--stage2), transparent 70%), radial-gradient(ellipse 70% 60% at 85% 90%, color-mix(in srgb, var(--hot) 35%, transparent), transparent 70%), var(--stage);
}
.th-wax { --stage: #2b0f05; --stage2: #5a1d06; --hot: #ff7a00; --sun: #ffd23f; --mint: #00c2a8; }
.th-neon { --stage: #020718; --stage2: #06204a; --hot: #00e5ff; --sun: #ff2bd6; --mint: #9dff3c; }
.th-luxe { --stage: #0b0b0b; --stage2: #2b2416; --hot: #d9b26a; --sun: #f3e2b3; --mint: #d9b26a; }
.show h2 { margin: 0; }
.orb { position: absolute; width: 40em; height: 40em; border-radius: 50%; filter: blur(4em); opacity: .35; background: var(--hot); animation: drift 14s ease-in-out infinite alternate; }
.orb.b { background: var(--sun); width: 30em; height: 30em; right: -10em; top: -10em; animation-duration: 18s; }
@keyframes drift { from { transform: translate(-10em, 10em) } to { transform: translate(20em, -4em) } }
.is-light .orb { display: none; }
/* Lumières de scène qui balaient, et qui flashent sur les temps forts de la musique */
.beam { position: absolute; top: -30%; width: 70%; height: 160%; opacity: .16; mix-blend-mode: screen; pointer-events: none; background: conic-gradient(from 180deg at 50% 0%, transparent 0 160deg, var(--hot) 172deg, transparent 184deg 360deg); transform-origin: 50% 0; animation: swing 7s ease-in-out infinite alternate; }
.beam.l { left: -20%; }
.beam.r { right: -20%; background: conic-gradient(from 180deg at 50% 0%, transparent 0 176deg, var(--sun) 188deg, transparent 200deg 360deg); animation-duration: 9s; animation-direction: alternate-reverse; }
@keyframes swing { from { transform: rotate(-18deg) } to { transform: rotate(22deg) } }
.beat .beam { opacity: .3; }
.flood { position: absolute; inset: 0; pointer-events: none; opacity: 0; background: radial-gradient(ellipse 60% 50% at 50% 40%, color-mix(in srgb, var(--hot) 22%, transparent), transparent 70%); }
.beat .flood { animation: flood .35s ease-out; }
@keyframes flood { from { opacity: 1 } to { opacity: 0 } }
.is-light .beam, .is-light .flood { display: none; }
/* ═══ Live interactif ═══ */
.overlay { overflow: hidden; isolation: isolate; }
.toasts.low { top: auto; bottom: 7em; }
.toasts.low .toast { font-size: 1.8em; }
.show:has(.overlay) .combo { display: none; }
/* Réactions */
.rain { position: absolute; inset: 0; pointer-events: none; z-index: 26; overflow: hidden; }
.drop { position: absolute; bottom: -2em; font-size: var(--s, 4em); opacity: 0; animation: rise var(--d, 3s) cubic-bezier(.3, .6, .4, 1) forwards; filter: drop-shadow(0 .1em .2em rgba(0, 0, 0, .4)); }
@keyframes rise { 0% { transform: translate(0, 0) scale(.4); opacity: 0 } 10% { opacity: 1; transform: translate(0, -8em) scale(1) } 100% { transform: translate(var(--dx, 2em), -80em) scale(1.1) rotate(var(--r, 10deg)); opacity: 0 } }
/* Question du public */
.bubble { position: absolute; left: 3em; bottom: 8em; z-index: 24; max-width: 58em; background: var(--ink); color: #1a0d24; border-radius: 2em 2em 2em .4em; padding: 1.4em 1.8em; box-shadow: 0 1em 3em rgba(0, 0, 0, .5); animation: bubIn .5s cubic-bezier(.2, 1.5, .4, 1); }
.bubble small { display: block; font-weight: 800; font-size: 1.7em; color: #8a3a6a; letter-spacing: .04em; }
.bubble p { margin: .2em 0 0; font-weight: 900; font-size: 3em; line-height: 1.15; }
.bubble .mic { display: inline-block; margin-top: .5em; font-weight: 800; font-size: 1.7em; background: #e8173d; color: #fff; border-radius: 2em; padding: .25em .8em; }
.is-square .bubble { left: 3em; right: 3em; max-width: none; bottom: 9em; }
.is-tall .bubble p { font-size: 3.6em; }
.is-tall .bubble small, .is-tall .bubble .mic { font-size: 2.4em; }
@keyframes bubIn { from { transform: translateY(3em) scale(.8); opacity: 0 } }
/* Défi, annonce, dernière chance */
.defi { position: absolute; left: 50%; top: 2.2em; transform: translateX(-50%); z-index: 7; width: 44em; background: rgba(0, 0, 0, .55); border: .15em solid var(--sun); border-radius: 1.4em; padding: .7em 1.2em; display: grid; gap: .5em; animation: bubIn .5s; }
.defi p { margin: 0; font-weight: 800; font-size: 1.7em; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dbar { height: 1.1em; border-radius: 1em; background: rgba(255, 255, 255, .15); overflow: hidden; }
.dbar i { display: block; height: 100%; background: linear-gradient(90deg, var(--hot), var(--sun)); transition: width .6s; }
.is-tall .defi { top: 9.2em; width: 66em; }
.is-tall .defi p { font-size: 2.5em; }
.is-square:not(.is-tall) .defi { top: 7em; width: 72em; }
.ann { position: absolute; right: 3em; top: 5.6em; z-index: 7; background: linear-gradient(90deg, var(--hot), var(--sun)); color: #1a0d24; font-weight: 800; font-size: 1.8em; padding: .35em .9em; border-radius: 2em; box-shadow: 0 .3em 1em rgba(0, 0, 0, .35); animation: pulseS 1.6s infinite; }
.is-square .ann { top: 2.6em; }
.is-tall .ann { top: 15.5em; font-size: 2.4em; }
@keyframes pulseS { 50% { scale: 1.08 } }
.lastv { position: absolute; inset: 0; pointer-events: none; z-index: 17; animation: vig 1.1s ease-in-out infinite; }
@keyframes vig { 50% { box-shadow: inset 0 0 8em 1.5em rgba(232, 23, 61, .75) } }
.lastb { position: absolute; left: 50%; top: 2.2em; transform: translateX(-50%); z-index: 27; background: #e8173d; color: #fff; font-weight: 800; font-size: 2.6em; padding: .35em 1.2em; border-radius: 2em; white-space: nowrap; box-shadow: 0 .4em 1.2em rgba(0, 0, 0, .4); animation: pulseS 1.1s infinite; }
.is-tall .lastb { top: 9.5em; font-size: 3.2em; }
.is-camera .lastv { animation: none; box-shadow: inset 0 0 6em 1em rgba(232, 23, 61, .5); }
/* Article mystère */
.mystery { display: grid; gap: 1.2em; justify-items: center; }
.mystery h2 { font-weight: 900; font-size: 6.4em; letter-spacing: -.02em; background: linear-gradient(90deg, var(--sun), var(--hot), var(--sun)); -webkit-background-clip: text; background-clip: text; color: transparent; background-size: 200% 100%; animation: shimmer 1.5s linear infinite; }
@keyframes shimmer { to { background-position: -200% 0 } }
.mbox { width: 40em; height: 40em; border-radius: 2em; overflow: hidden; display: grid; place-items: center; background: rgba(255, 255, 255, .06); border: .25em solid var(--sun); box-shadow: 0 0 4em color-mix(in srgb, var(--hot) 55%, transparent); }
.mbox img { width: 100%; height: 100%; object-fit: cover; transition: transform 1.4s cubic-bezier(.4, 0, .2, 1), filter 1.4s; }
.gbox { font-size: 22em; line-height: 1; animation: shake .45s ease-in-out infinite; }
@keyframes shake { 25% { transform: rotate(-7deg) scale(1.03) } 75% { transform: rotate(7deg) } }
.mhint { font-weight: 800; font-size: 3em; min-height: 1.3em; text-align: center; }
.mhint span { display: inline-block; background: color-mix(in srgb, var(--hot) 45%, transparent); border-radius: .4em; padding: 0 .4em; margin: .1em; animation: slam .5s cubic-bezier(.2, 1.6, .4, 1); }
.is-tall .mbox { width: 60em; height: 60em; }
.is-tall .mhint { font-size: 3.6em; }
.is-square:not(.is-tall) .mbox { width: 42em; height: 36em; }
.whiteout { position: absolute; inset: 0; background: #fff; z-index: 29; animation: wo .7s ease-out forwards; pointer-events: none; }
@keyframes wo { from { opacity: 1 } to { opacity: 0 } }
/* Les 3 premiers */
.podium { display: grid; gap: 2em; justify-items: center; width: 90%; }
.podium h2 { font-weight: 900; font-size: 5.6em; }
.steps3 { display: grid; grid-template-columns: repeat(3, 1fr); align-items: end; gap: 1.6em; width: min(70em, 100%); }
.step3 { display: grid; justify-items: center; gap: .8em; }
.step3 .who { font-weight: 800; font-size: 2.6em; min-height: 1.3em; }
.step3 .who.in { animation: slam .55s cubic-bezier(.2, 1.6, .4, 1); }
.step3 .block { width: 100%; border-radius: 1em 1em 0 0; display: grid; place-items: center; font-weight: 900; font-size: 7em; color: #1a0d24; box-shadow: inset 0 .1em 0 rgba(255, 255, 255, .5); }
.step3:nth-child(1) .block { height: calc(var(--u) * 13); background: linear-gradient(#e6e6e6, #a9a9a9); }
.step3:nth-child(2) .block { height: calc(var(--u) * 18); background: linear-gradient(#ffe27a, #d39a1a); }
.step3:nth-child(3) .block { height: calc(var(--u) * 9); background: linear-gradient(#f0b27a, #a8642b); }
.is-tall .podium h2 { font-size: 5em; }
.is-tall .steps3 { width: 100%; }
.is-tall .step3:nth-child(1) .block { height: calc(var(--u) * 22); }
.is-tall .step3:nth-child(2) .block { height: calc(var(--u) * 30); }
.is-tall .step3:nth-child(3) .block { height: calc(var(--u) * 15); }
/* Carte du live */
.mapwrap { display: grid; gap: 1.2em; justify-items: center; width: 100%; }
.maptitle { font-weight: 900; font-size: 3.4em; white-space: nowrap; }
.is-tall .maptitle { font-size: 3.6em; white-space: normal; text-align: center; }
.map { position: relative; width: min(92em, 92%); height: calc(var(--u) * 46); border-radius: 2em; background: radial-gradient(ellipse at 50% 40%, rgba(255, 255, 255, .06), transparent 70%); border: .15em solid rgba(255, 255, 255, .15); }
.is-tall .map { height: calc(var(--u) * 90); width: 90%; }
.is-square:not(.is-tall) .map { height: calc(var(--u) * 56); width: 90%; }
.lagoon { position: absolute; left: 0; right: 0; top: 55%; height: 9%; background: linear-gradient(90deg, transparent, rgba(56, 189, 248, .35) 15%, rgba(56, 189, 248, .35) 85%, transparent); border-radius: 50%; transform: rotate(-4deg); }
.pin { position: absolute; transform: translate(-50%, -50%); display: grid; justify-items: center; gap: .3em; }
.pin i { display: block; width: var(--r, 2em); height: var(--r, 2em); border-radius: 50%; background: var(--hot); box-shadow: 0 0 0 .3em color-mix(in srgb, var(--hot) 35%, transparent), 0 0 2em var(--hot); transition: width .6s, height .6s; }
.pin b { font-weight: 800; font-size: 1.8em; white-space: nowrap; text-shadow: 0 .1em .3em #000; }
.is-tall .pin b { font-size: 2.4em; }
.pin.top i { background: var(--sun); box-shadow: 0 0 0 .3em color-mix(in srgb, var(--sun) 35%, transparent), 0 0 2.4em var(--sun); }
.ripple { position: absolute; width: 2em; height: 2em; border-radius: 50%; border: .3em solid var(--sun); transform: translate(-50%, -50%); animation: rip 1.2s ease-out forwards; pointer-events: none; }
@keyframes rip { to { width: 16em; height: 16em; opacity: 0 } }
/* Défi réussi, fin du live */
.unlock { display: grid; gap: 1.4em; justify-items: center; }
.gift { font-size: 20em; line-height: 1; animation: giftPop 1s cubic-bezier(.2, 1.6, .4, 1); }
@keyframes giftPop { 0% { transform: scale(0) rotate(-30deg) } 60% { transform: scale(1.2) rotate(8deg) } 100% { transform: scale(1) } }
.unlock h2 { font-weight: 900; font-size: 7em; color: var(--sun); }
.unlock p, .bye p { margin: 0; font-weight: 800; font-size: 3.4em; }
.rays2 { position: absolute; inset: -50%; background: repeating-conic-gradient(from 0deg, color-mix(in srgb, var(--sun) 22%, transparent) 0 10deg, transparent 10deg 22deg); animation: spin2 12s linear infinite; z-index: -1; }
@keyframes spin2 { to { transform: rotate(360deg) } }
.bye h2 { font-weight: 900; font-size: 8em; color: var(--sun); }
.is-light .rays2, .is-camera .rays2 { display: none; }
/* Mode caméra : écran plus doux à filmer (le téléphone règle l'image sur le visage, l'écran derrière ressort trop fort) :
   blancs cassés, photos baissées, plus de lueurs, de reflet ni de lumières qui balaient */
.is-camera { --stage: #14223a; --stage2: #14223a; --ink: #e6e0d6; --dim: #d6cfc3; --hot: #c9762a; --sun: #dcae24; --mint: #1f9e6b; background: var(--stage); }
.is-camera .beam, .is-camera .flood, .is-camera .shine, .is-camera .orb { display: none; }
/* La photo (souvent sur fond blanc) est le point le plus clair de l'écran : la caméra règle sur elle et tout le reste paraît terne */
.is-camera .pic { filter: brightness(.58) contrast(.9) saturate(1.05); }
.is-camera .photo-bg { filter: blur(2.6em) saturate(1.2) brightness(.35); animation: none; }
.is-camera .photo { background: var(--stage); box-shadow: 0 0 0 .3em color-mix(in srgb, var(--ink) 25%, transparent); }
.is-camera .code, .is-camera .combo { text-shadow: none; }
.is-camera .toast { background: #b9b1a7; box-shadow: none; }
.is-camera .live-dot { background: #c2142f; }
.is-camera .cell { background: #b9b1a7; }
.is-camera.beat .photo, .is-camera.beat .code { animation: none; }
/* Textes : crème (jamais blanc pur, qui bave), moins gras, plus grands ; « Envoie A2 » dans un cartouche plein */
.is-camera .title { font-weight: 700; font-size: 4.4em; text-shadow: none; }
.is-camera.is-square .title { font-size: 3.5em; }
.is-camera.is-tall .title { font-size: 5.2em; }
.is-camera .how { color: var(--ink); font-size: 2.8em; }
.is-camera.is-square .how { font-size: 2.6em; }
.is-camera.is-tall .how { font-size: 3.5em; }
.is-camera .how b { background: var(--sun); color: var(--stage); padding: .05em .35em; }
.is-camera .stock p { font-size: 2.6em; color: var(--ink); }
.is-camera.is-tall .stock p { font-size: 3.4em; }
.is-camera .stock .bar { background: rgba(230, 224, 214, .25); }
.is-camera .stock .bar i { background: var(--mint); }
.is-camera .bio, .is-camera .brand b { color: var(--ink); }
/* Bandeau du bas fixe (un texte qui défile se brouille à la caméra), sur fond uni */
.is-camera .ticker { background: #0d1626; }
.is-camera .ticker div { animation: none; padding-left: 1.5em; font-size: 2.6em; }
.is-camera.is-tall .ticker div { font-size: 3.4em; }
.is-camera .ticker span:not(:first-child) { display: none; }
/* Mode léger (vieilles télés) : pas de flou ni de reflet */
.is-light .photo-bg, .is-light .shine { display: none; }
.is-light .pic { filter: none; transition: opacity .8s; }
.fx { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; z-index: 30; }

.brand { position: absolute; left: 3em; top: 2.4em; display: flex; align-items: center; gap: 1em; z-index: 5; }
.live-dot { display: inline-flex; align-items: center; gap: .6em; background: #e8173d; color: #fff; font-weight: 800; font-size: 1.5em; padding: .35em .8em; border-radius: .4em; letter-spacing: .06em; }
.live-dot i { width: .6em; height: .6em; border-radius: 50%; background: #fff; animation: blink 1s infinite; }
@keyframes blink { 50% { opacity: .2 } }
.brand b { font-weight: 800; font-size: 2.2em; letter-spacing: -.02em; }
.bio { position: absolute; right: 3em; top: 2.6em; font-weight: 700; font-size: 1.6em; color: var(--sun); z-index: 5; }

.product { position: absolute; inset: 0; display: grid; grid-template-columns: 54% 1fr; align-items: center; gap: 3.5em; padding: 7.5em 4em 8em; }
/* Carré et vertical : la photo prend toute la largeur, le code la chevauche */
.is-square .product { display: flex; flex-direction: column; align-items: stretch; gap: 0; padding: 8em 3em 7.5em; }
.is-tall .product { padding: 10.5em 3em 8.5em; }
.photo { position: relative; width: 100%; height: 100%; border-radius: 2.6em; overflow: hidden; background: #000; box-shadow: 0 2em 5em rgba(0, 0, 0, .55), 0 0 0 .25em color-mix(in srgb, var(--ink) 18%, transparent); animation: photoIn .8s cubic-bezier(.2, 1.3, .4, 1); }
.is-square .photo { flex: 1 1 auto; min-height: 0; height: auto; }
@keyframes photoIn { from { transform: scale(.7) rotate(-6deg); opacity: 0; filter: blur(1em) } }
/* Fond : la photo floutée aux couleurs vives (plus de bandes vides autour d'une photo pas carrée) */
.photo-bg { position: absolute; inset: -12%; background-size: cover; background-position: center; filter: blur(2.6em) saturate(1.7) brightness(.7); animation: bgdrift 16s ease-in-out infinite alternate; }
@keyframes bgdrift { from { transform: scale(1.1) } to { transform: scale(1.25) translate(3%, -3%) } }
/* Photo : couleurs plus vives, zoom lent, puis gros plan */
.pic { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; opacity: 0; filter: contrast(1.1) saturate(1.28) brightness(1.06); transform: scale(1); transform-origin: 50% 42%; transition: opacity .8s, transform 3.4s cubic-bezier(.45, 0, .2, 1); }
.pic.on { opacity: 1; transform: scale(1.06); }
.pic.zoom { transform: scale(1.65); }
.shine { position: absolute; inset: 0; pointer-events: none; background: linear-gradient(105deg, transparent 35%, rgba(255, 255, 255, .38) 48%, transparent 60%); transform: translateX(-120%); animation: shine 5.5s ease-in-out infinite; mix-blend-mode: overlay; }
@keyframes shine { 0%, 55% { transform: translateX(-120%) } 85%, 100% { transform: translateX(120%) } }
.dots { position: absolute; left: 50%; bottom: 1.4em; transform: translateX(-50%); display: flex; gap: .6em; }
.is-square .dots { left: auto; right: 1.6em; top: 1.6em; bottom: auto; transform: none; }
.dots i { width: 1em; height: 1em; border-radius: 1em; background: rgba(255, 255, 255, .45); transition: width .4s; }
.dots i.on { width: 2.6em; background: var(--ink); }
.no-photo { position: absolute; inset: 0; display: grid; place-items: center; font-weight: 900; font-size: 18em; color: color-mix(in srgb, var(--ink) 25%, transparent); background: var(--stage2); }
.beat .photo { animation: thump .28s ease-out; }
@keyframes thump { 0% { box-shadow: 0 2em 5em rgba(0, 0, 0, .55), 0 0 0 .5em var(--sun), 0 0 5em color-mix(in srgb, var(--hot) 70%, transparent) } 100% { box-shadow: 0 2em 5em rgba(0, 0, 0, .55), 0 0 0 .25em color-mix(in srgb, var(--ink) 18%, transparent) } }
.info { display: grid; gap: 1.3em; align-content: center; position: relative; z-index: 4; }
.is-square .info { grid-template-columns: auto 1fr; align-items: end; column-gap: 2em; row-gap: .8em; margin-top: -5.5em; padding: 0 1em; }
.is-tall .info { grid-template-columns: 1fr; margin-top: -8.5em; gap: 1.4em; }
.code { font-weight: 900; font-size: 15em; line-height: .85; letter-spacing: -.04em; color: var(--sun); text-shadow: 0 .04em 0 rgba(0, 0, 0, .4), 0 0 .25em color-mix(in srgb, var(--sun) 45%, transparent); animation: slam .55s cubic-bezier(.2, 1.6, .4, 1); }
.is-square .code { font-size: 12em; grid-row: span 2; }
.is-tall .code { font-size: 19em; grid-row: auto; }
.beat .code { animation: codeBeat .25s ease-out; }
@keyframes codeBeat { from { transform: scale(1.05) } }
@keyframes slam { 0% { transform: scale(3); opacity: 0 } 60% { transform: scale(.9); opacity: 1 } 100% { transform: scale(1) } }
.title { font-weight: 800; font-size: 4em; line-height: 1.05; letter-spacing: -.02em; }
.is-square .title { font-size: 3.2em; }
.is-tall .title { font-size: 4.8em; }
.sizes { display: flex; gap: .8em; flex-wrap: wrap; }
.is-square .sizes { display: none; }
.is-tall .sizes { display: flex; }
.sizes span { font-weight: 800; font-size: 2.4em; background: color-mix(in srgb, var(--ink) 12%, transparent); border: .1em solid color-mix(in srgb, var(--ink) 45%, transparent); border-radius: .5em; padding: .15em .75em; }
.is-tall .sizes span { font-size: 3.2em; }
.sizes span.out { opacity: .35; text-decoration: line-through; }
.how { font-weight: 700; font-size: 2.4em; color: var(--dim); }
.is-square .how { font-size: 2.3em; }
.is-tall .how { font-size: 3.1em; }
.how b { color: var(--ink); background: color-mix(in srgb, var(--hot) 45%, transparent); padding: 0 .3em; border-radius: .2em; }
.stock { display: grid; gap: .5em; width: 100%; }
.is-square .stock { grid-column: 1 / -1; }
.stock .bar { height: 1.6em; border-radius: 1em; background: rgba(255, 255, 255, .12); overflow: hidden; }
.stock .bar i { display: block; height: 100%; background: linear-gradient(90deg, var(--mint), var(--sun)); transition: width .6s cubic-bezier(.2, 1, .3, 1); }
.stock p { margin: 0; font-weight: 800; font-size: 2.2em; }
.is-tall .stock .bar { height: 2.2em; }
.is-tall .stock p { font-size: 3em; }
.stock.low p { color: var(--hot); animation: pulse .7s ease-in-out infinite; transform-origin: left; }
.stock.low .bar i { background: var(--hot); }
@keyframes pulse { 50% { transform: scale(1.08) } }
.stamp { position: absolute; left: 50%; top: 48%; transform: translate(-50%, -50%) rotate(-14deg); font-weight: 900; font-size: 13em; color: #ff2747; border: .09em solid #ff2747; padding: 0 .25em; border-radius: .12em; background: rgba(18, 6, 31, .55); z-index: 20; animation: stampIn .45s cubic-bezier(.3, 1.7, .5, 1) both; }
.is-square .stamp { font-size: 9em; }
.is-tall .stamp { font-size: 13em; top: 38%; }
@keyframes stampIn { from { transform: translate(-50%, -50%) rotate(-14deg) scale(3); opacity: 0 } }
.sub-stamp { position: absolute; left: 50%; top: 70%; transform: translateX(-50%); font-weight: 800; font-size: 2.6em; background: var(--ink); color: var(--stage); padding: .3em .9em; border-radius: 2em; z-index: 21; white-space: nowrap; animation: up .4s .35s both; }
.is-tall .sub-stamp { top: 54%; white-space: normal; width: 80%; text-align: center; font-size: 3em; border-radius: 1em; }
@keyframes up { from { transform: translate(-50%, 1em); opacity: 0 } }

.spot { position: absolute; inset: 0; z-index: 15; pointer-events: none; background: radial-gradient(circle 22em at 50% 45%, rgba(255, 255, 255, .35), transparent 60%); mix-blend-mode: screen; animation: sweep 1.1s ease-in-out forwards; }
@keyframes sweep { 0% { transform: translateX(-70%); opacity: 0 } 20% { opacity: 1 } 100% { transform: translateX(70%); opacity: 0 } }

.toasts { position: absolute; right: 3em; bottom: 9em; display: grid; gap: 1em; justify-items: end; z-index: 25; }
.is-square .toasts { left: 3em; right: 3em; justify-items: center; }
.is-tall .toasts { top: calc(var(--u) * 17); bottom: auto; }
.is-tall .toast { font-size: 3.2em; }
.toast { background: var(--ink); color: #1a0d24; font-weight: 800; font-size: 2.2em; padding: .5em .9em; border-radius: .6em; box-shadow: 0 .4em 1.2em rgba(0, 0, 0, .4); animation: toastIn .5s cubic-bezier(.2, 1.5, .4, 1); }
@keyframes toastIn { from { transform: translateX(3em) scale(.8); opacity: 0 } }
.combo { position: absolute; left: 50%; top: calc(var(--u) * 7); transform: translateX(-50%); white-space: nowrap; font-weight: 900; font-size: 4.4em; color: var(--sun); z-index: 25; text-shadow: 0 .06em 0 rgba(0, 0, 0, .4); animation: comboIn .4s cubic-bezier(.2, 1.8, .4, 1); }
.is-square .combo { font-size: 3.4em; }
.is-tall .combo { font-size: 5em; top: calc(var(--u) * 9.5); }
@keyframes comboIn { from { opacity: 0; scale: 2 } }
.goal-mini { background: rgba(0, 0, 0, .5); font-weight: 900; font-size: 1.6em; padding: .25em .8em; border-radius: 1em; }
.ticker { position: absolute; left: 0; right: 0; bottom: 0; height: 6em; background: rgba(0, 0, 0, .45); display: flex; align-items: center; overflow: hidden; z-index: 6; border-top: .15em solid color-mix(in srgb, var(--hot) 70%, transparent); }
.ticker div { white-space: nowrap; font-weight: 800; font-size: 2.2em; animation: tick 26s linear infinite; padding-left: 100%; }
.ticker span { margin-right: 3em; }
.is-tall .ticker { height: 7em; }
.is-tall .ticker div { font-size: 3em; }
.is-tall .brand { top: 3em; }
.is-tall .live-dot { font-size: 2.2em; }
.is-tall .brand b { font-size: 3.2em; }
.is-tall .goal-mini { font-size: 2.2em; }
.is-square .bio { display: none; }
@keyframes tick { to { transform: translateX(-100%) } }

.overlay { position: absolute; inset: 0; display: grid; place-items: center; z-index: 18; text-align: center; background: radial-gradient(ellipse at center, rgba(18, 6, 31, .55), rgba(18, 6, 31, .92)); }
.overlay.soft { background: none; z-index: 4; }
.kicker { margin: 0; font-weight: 800; font-size: 2.4em; letter-spacing: .2em; text-transform: uppercase; color: var(--dim); }
.is-tall .kicker { font-size: 3em; letter-spacing: .12em; }
.is-tall .count { font-size: 44em; }
.count { font-weight: 900; font-size: 30em; line-height: 1; color: var(--sun); animation: countPop .9s ease-out both; }
@keyframes countPop { 0% { transform: scale(2.4); opacity: 0 } 25% { transform: scale(1); opacity: 1 } 100% { transform: scale(.7); opacity: 0 } }
.flash h2 { font-weight: 900; font-size: 8em; color: var(--sun); letter-spacing: -.02em; animation: pulse .9s infinite; }
.flash .clock { font-weight: 900; font-size: 16em; line-height: 1; font-variant-numeric: tabular-nums; }
.flash .clock.end { color: var(--hot); }
.is-tall .flash h2 { font-size: 7.2em; line-height: 1; }
.is-tall .flash .clock { font-size: 20em; }
.flash { display: flex; align-items: center; justify-content: center; gap: 4em; }
.flash-text { display: grid; justify-items: center; }
.flash-photo { position: relative; flex: none; width: 36em; height: 36em; border-radius: 2em; overflow: hidden; background: #fff; border: .3em solid var(--sun); box-shadow: 0 0 4em color-mix(in srgb, var(--hot) 60%, transparent); animation: photoIn .8s cubic-bezier(.2, 1.3, .4, 1); }
.flash-photo img { width: 100%; height: 100%; object-fit: contain; }
.flash-photo b { position: absolute; left: .4em; top: .3em; font-weight: 900; font-size: 5em; color: var(--sun); text-shadow: 0 .05em .2em rgba(0, 0, 0, .6); }
.is-square .flash { flex-direction: column; gap: 1.2em; }
.is-square:not(.is-tall) .flash-photo { width: 28em; height: 28em; }
.is-square:not(.is-tall) .flash h2 { font-size: 6em; }
.is-square:not(.is-tall) .flash .clock { font-size: 12em; }
.is-tall .flash-photo { width: 54em; height: 54em; }
.is-camera .flash-photo img { filter: brightness(.75); }
.is-tall .goal { width: 88%; }
.is-tall .goal h2, .is-tall .match h2, .is-tall .wheelbox h2 { font-size: 5.6em; line-height: 1.05; }
.is-tall .num { font-size: 8em; }
.is-tall .match { width: 90%; gap: 2.2em; }
.goal { width: min(80%, 70em); display: grid; gap: 1.6em; }
.goal h2, .match h2, .wheelbox h2 { font-weight: 900; font-size: 5em; letter-spacing: -.02em; }
.gbar { height: 4em; border-radius: 3em; background: rgba(255, 255, 255, .12); overflow: hidden; border: .2em solid rgba(255, 255, 255, .2); }
.gbar i { display: block; height: 100%; width: 0; background: linear-gradient(90deg, var(--hot), var(--sun)); transition: width 2.4s cubic-bezier(.3, .9, .3, 1); }
.num { font-weight: 900; font-size: 6em; font-variant-numeric: tabular-nums; }
.num small { font-size: .5em; color: var(--dim); }
.match { width: min(86%, 80em); display: grid; gap: 1.4em; }
.row { display: grid; grid-template-columns: 12em 1fr 4em; align-items: center; gap: 1.2em; font-weight: 800; font-size: 2.6em; text-align: left; }
.is-square .row { grid-template-columns: 9em 1fr 3em; font-size: 2.2em; }
.is-tall .row { grid-template-columns: 9em 1fr 2.4em; font-size: 3.3em; }
.track { height: 1.3em; border-radius: 1em; background: rgba(255, 255, 255, .1); overflow: hidden; }
.track i { display: block; height: 100%; border-radius: 1em; background: var(--sun); animation: grow 1.6s cubic-bezier(.3, 1, .3, 1) both; transform-origin: left; }
.row:nth-of-type(2) .track i { background: var(--hot); }
.row:nth-of-type(3) .track i { background: var(--mint); }
.row:nth-of-type(4) .track i { background: #9b7bff; }
@keyframes grow { from { transform: scaleX(0) } }
.row b { font-variant-numeric: tabular-nums; text-align: right; }
.wheelbox { display: grid; gap: 1.4em; justify-items: center; }
.wheel { position: relative; width: 34em; height: 34em; }
.is-square .wheel { width: 30em; height: 30em; }
.is-tall .wheel { width: 58em; height: 58em; }
.wheel canvas { width: 100%; height: 100%; transition: transform 5.2s cubic-bezier(.12, .8, .12, 1); }
.wheel::after { content: ''; position: absolute; left: 50%; top: -1.6em; transform: translateX(-50%); border: 1.6em solid transparent; border-top: 3em solid var(--ink); filter: drop-shadow(0 .3em .3em rgba(0, 0, 0, .5)); }
.winner-line { min-height: 4.5em; }
.winner { margin: 0; font-weight: 900; font-size: 3.6em; color: var(--sun); animation: comboIn .5s both; }
.is-tall .winner { font-size: 4.2em; padding: 0 1em; }
.inter { display: grid; gap: 2.6em; justify-items: center; }
.inter h2 { font-weight: 900; font-size: 6em; }
.steps { display: flex; gap: 2.4em; flex-wrap: wrap; justify-content: center; }
.steps div { background: rgba(255, 255, 255, .08); border: .15em solid rgba(255, 255, 255, .18); border-radius: 1.4em; padding: 1.6em 2em; width: 20em; font-weight: 800; font-size: 2.2em; animation: up2 .5s both; }
.steps div:nth-child(2) { animation-delay: .25s; }
.steps div:nth-child(3) { animation-delay: .5s; }
.steps div b { display: block; font-size: 2.4em; color: var(--sun); }
.is-square .steps div { width: 15em; font-size: 1.8em; }
.is-tall .inter h2 { font-size: 7em; line-height: 1; }
.is-tall .steps { flex-direction: column; gap: 2em; }
.is-tall .steps div { width: 19em; font-size: 3.2em; padding: 1em 1.2em; }
.is-tall .steps div b { font-size: 1.8em; }
@keyframes up2 { from { transform: translateY(1.5em); opacity: 0 } }
.grid { position: absolute; inset: 0; padding: 8em 4em 8em; display: grid; gap: 1.6em; align-content: center; text-align: center; }
.grid h2 { font-weight: 900; font-size: 4em; }
.cells { display: grid; grid-template-columns: repeat(5, 1fr); gap: 1.4em; }
.is-tall .grid h2 { font-size: 5.6em; }
.is-tall .cell b { font-size: 3.2em; }
.cell { position: relative; aspect-ratio: 1; border-radius: 1.4em; overflow: hidden; background: #fff; }
.cell img { width: 100%; height: 100%; object-fit: contain; }
.cell b { position: absolute; left: .4em; top: .4em; background: #e8173d; color: #fff; font-weight: 900; font-size: 2.4em; border-radius: .3em; padding: 0 .3em; }
.cell.out { opacity: .4; }
@media (prefers-reduced-motion: reduce) { .show *, .show *::before, .show *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; } }
</style>
