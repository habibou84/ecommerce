<script setup>
import StateBadge from '../../components/StateBadge.vue'
import { computed, defineAsyncComponent, onMounted, onUnmounted, ref, watch } from 'vue'
import Alert from '../../components/Alert.vue'
import QrScanner from '../../components/QrScanner.vue'
import { rawCode } from '../../lib/parcelLabels'
import http, { errorMessage } from '../../lib/http'
import { newMissionAlert } from '../../lib/alertSound'
import { cachedGet, loadMissions, offline, savedRoute, saveRoute, sendStep } from '../../lib/courierOffline'
import { money } from '../../lib/format'
import { addDays, autoOrder, clock, currentPosition, dayName, dueDay, itineraryUrl, routeOrder, shortDay, tabOf, today, tomorrow } from '../../lib/route'
import { useRouter } from 'vue-router'

const router = useRouter()
const missions = ref(null)
const me = ref(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const editing = ref(false) // le livreur change l'ordre de sa tournée
const draft = ref([]) // ordre en cours de modification
let timer
// Missions déjà connues : une nouvelle mission à accepter déclenche le son
let known = null

const isOffer = (m) => m.acceptance === 'pending' && m.status === 'awaiting_pickup' && !m.overdue

async function load() {
  try {
    const [m, p] = await Promise.all([loadMissions(), cachedGet('/courier/me', 'me')])
    const incoming = m.filter(isOffer).map((x) => x.id)
    if (known && incoming.some((id) => !known.has(id))) newMissionAlert()
    known = new Set([...(known ?? []), ...incoming])
    offline.offers = incoming.length
    missions.value = m
    me.value = p
    error.value = ''
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(() => {
  load()
  // Nouvelles missions toutes les 20 secondes, application ouverte à l'écran
  timer = setInterval(() => !editing.value && document.visibilityState === 'visible' && load(), 20000)
})
onUnmounted(() => clearInterval(timer))
watch(() => offline.synced, () => !editing.value && load())

// Colis scanné : sa course s'ouvre (cherchée dans les courses gardées sur le téléphone, donc aussi sans réseau)
const scanning = ref(false)
function scanned(input) {
  scanning.value = false
  const code = rawCode(input)
  const found = code && (missions.value ?? []).find((m) => (m.labels ?? []).includes(code))
  if (found) router.push(`/livreur/missions/${found.id}`)
  else error.value = code ? 'Ce colis n\'est dans aucune de vos courses.' : 'Ce n\'est pas une étiquette de colis.'
}

// ── Jour affiché : aujourd'hui par défaut, ou un autre jour pour revoir ou s'organiser ──
const day = ref(today())
const isToday = computed(() => day.value === today())
const isPast = computed(() => day.value < today())
const isTomorrow = computed(() => day.value === tomorrow())
const datePicker = ref(null)
function goTo(d) {
  if (!d) return
  day.value = d
  editing.value = false
  notice.value = ''
}

// Jour passé : ce qu'il a fait ce jour-là, lu sur le serveur (il faut du réseau)
const history = ref(null)
const historyMeta = ref(null)
watch(day, async (d) => {
  history.value = null
  historyMeta.value = null
  if (d >= today()) return
  try {
    const res = (await http.get('/courier/missions', { params: { day: d } })).data
    if (day.value === d) {
      history.value = res.data
      historyMeta.value = res.meta
    }
  } catch (e) {
    error.value = errorMessage(e)
  }
})

// Plusieurs boutiques : filtre par boutique (toutes par défaut)
const storeFilter = ref('')
const storeNames = computed(() => [...new Set((missions.value ?? []).map((m) => m.store).filter(Boolean))])
const byStore = (list) => (storeFilter.value ? list.filter((m) => m.store === storeFilter.value) : list)

// ── Les trois onglets du jour ──
const OUTCOME_TAB = { delivered: 'deliver', failed: 'deliver', returned: 'return', picked_up: 'pickup' }
const lists = computed(() => {
  const out = { pickup: [], deliver: [], return: [] }
  if (isPast.value) {
    for (const m of byStore(history.value ?? [])) out[OUTCOME_TAB[m.outcome] ?? 'deliver'].push(m)
    return out
  }
  for (const m of byStore(missions.value ?? [])) {
    const tab = tabOf(m, day.value)
    if (tab) out[tab].push(m)
  }
  // Nouvelles courses à accepter en tête ; colis à livrer dans l'ordre de la tournée
  out.pickup.sort((a, b) => isOffer(b) - isOffer(a))
  if (isToday.value) out.deliver = routeOrder(out.deliver, savedRoute())
  return out
})
const TABS = [['pickup', 'À ramasser'], ['deliver', 'À livrer'], ['return', 'À retourner']]
const tab = ref('pickup')
const chosen = ref(false) // le livreur a choisi un onglet : on ne le change plus pour lui
watch([lists, day], () => {
  if (chosen.value || lists.value[tab.value].length) return
  tab.value = TABS.map(([k]) => k).find((k) => lists.value[k].length) ?? 'pickup'
}, { immediate: true })
function pick(k) {
  tab.value = k
  chosen.value = true
  editing.value = false
}
const shown = computed(() => (editing.value ? draft.value : lists.value[tab.value]))

// Colis en main dont la date est passée, échecs en attente : chez le livreur mais pas à lui de décider
const waiting = computed(() => (isToday.value ? byStore(missions.value ?? []) : []))
const overdueInHand = computed(() => waiting.value.filter((m) => m.overdue && m.status !== 'awaiting_pickup'))
const awaitingDecision = computed(() => waiting.value.filter((m) => m.awaiting_decision))
const doneToday = computed(() => (isToday.value ? byStore(missions.value ?? []).filter((m) => ['delivered', 'returned', 'cancelled', 'lost'].includes(m.status)) : []))

// Nouvelles courses à accepter un autre jour que celui affiché
const offersElsewhere = computed(() => (isPast.value ? [] : byStore(missions.value ?? []).filter((m) => isOffer(m) && dueDay(m) !== day.value)))
const offersOn = (d) => offersElsewhere.value.filter((m) => dueDay(m) === d).length
// Demain a des courses : point sur la flèche
const hasTomorrow = computed(() => (missions.value ?? []).some((m) => tabOf(m, tomorrow())))

// Bilan du jour
const summary = computed(() => {
  if (isPast.value) return historyMeta.value && { delivered: historyMeta.value.delivered, failed: historyMeta.value.failed, collected: historyMeta.value.collected }
  if (isToday.value && me.value) return { delivered: me.value.today.delivered, failed: me.value.today.failed, collected: me.value.today.collected }
  return null
})
const toCollect = (list) => list.reduce((sum, m) => sum + Math.min(m.cod_amount, m.amount_due ?? m.cod_amount), 0)

// Ramassage : aujourd'hui, ou la veille pour les courses de demain
const canPickUp = computed(() => isToday.value || isTomorrow.value)
const toPickUp = computed(() => (canPickUp.value ? lists.value.pickup.filter((m) => !isOffer(m) && !m.pending_sync) : []))
// Itinéraire : les colis à livrer dans l'ordre
const itinerary = computed(() => itineraryUrl(lists.value.deliver))
// Nouvelle commune dans la liste : un titre la sépare de la précédente
const startsArea = (list, i) => i === 0 || (list[i].commune ?? '') !== (list[i - 1].commune ?? '')

// Réponse à une nouvelle mission
const declining = ref(null)
const reason = ref('')
const REASONS = ['Trop loin', 'Trop de colis', 'Pas disponible', 'Moto en panne']
async function accept(m) {
  busy.value = true
  error.value = ''
  try {
    await http.post(`/courier/missions/${m.id}/accept`)
    notice.value = `Course ${m.reference} acceptée ✓`
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
async function decline() {
  busy.value = true
  error.value = ''
  try {
    await http.post(`/courier/missions/${declining.value.id}/decline`, { reason: reason.value })
    notice.value = 'Course refusée : le marchand est prévenu.'
    declining.value = null
    reason.value = ''
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

const confirmingPickUp = ref(false)
async function pickUpAll() {
  confirmingPickUp.value = false
  busy.value = true
  error.value = ''
  let queued = 0
  const list = toPickUp.value
  try {
    for (const m of list) if (await sendStep(m, 'in_transit')) queued++
    notice.value = queued ? `${list.length} colis récupérés. Envoi au marchand dès que le réseau revient.` : 'Colis récupérés ✓'
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
    await load()
  }
}

function edit() {
  draft.value = [...lists.value.deliver]
  editing.value = true
}
function move(i, delta) {
  const list = [...draft.value]
  const [m] = list.splice(i, 1)
  list.splice(i + delta, 0, m)
  draft.value = list
}
async function auto() {
  busy.value = true
  const here = await currentPosition()
  draft.value = autoOrder(draft.value, here)
  busy.value = false
  notice.value = here ? 'Rangé par quartier et du plus proche au plus loin de vous.' : 'Rangé par commune et par quartier.'
}
function saveOrder() {
  saveRoute(draft.value.map((m) => m.id))
  editing.value = false
  notice.value = 'Ordre de la tournée enregistré ✓'
}

// ── Carte de la tournée (onglet « À livrer » d'aujourd'hui) ──
const MapView = defineAsyncComponent(() => import('../../components/MapView.vue'))
const view = ref('list') // 'list' | 'map'
const here = ref(null) // position du livreur (départ), si le téléphone la donne
const selectedStop = ref(null)
watch(view, async (v) => {
  if (v === 'map' && !here.value) here.value = await currentPosition()
})
const mapStops = computed(() => lists.value.deliver.map((m, i) => ({ m, n: i + 1 })))
const mapPoints = computed(() => {
  const located = mapStops.value.filter(({ m }) => m.point)
  const start = here.value ? { lat: here.value.latitude, lng: here.value.longitude } : lists.value.deliver.find((m) => m.origin_point)?.origin_point
  return [
    ...(start ? [{ id: 'start', lat: start.lat, lng: start.lng, kind: 'start', title: 'Départ' }] : []),
    ...located.map(({ m, n }) => ({ id: m.id, lat: m.point.lat, lng: m.point.lng, label: String(n), kind: m.point.exact ? 'stop' : 'approx', last: n === lists.value.deliver.length, title: `${n} · ${m.place}` })),
  ]
})
const noPoint = computed(() => mapStops.value.filter(({ m }) => !m.point))
const selected = computed(() => mapStops.value.find(({ m }) => m.id === selectedStop.value) ?? null)
const goUrl = (m) => (m.point ? `https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=${m.point.lat},${m.point.lng}` : itineraryUrl([m]))
// Distance à vol d'oiseau depuis le départ (km)
function km(a, b) {
  const r = (d) => (d * Math.PI) / 180
  const h = Math.sin(r(b.lat - a.lat) / 2) ** 2 + Math.cos(r(a.lat)) * Math.cos(r(b.lat)) * Math.sin(r(b.lng - a.lng) / 2) ** 2
  return 12742 * Math.asin(Math.sqrt(h))
}
const distanceTo = (m) => (here.value && m.point ? km({ lat: here.value.latitude, lng: here.value.longitude }, m.point).toFixed(1).replace('.', ',') : null)

const EMPTY = { pickup: 'Rien à ramasser', deliver: 'Rien à livrer', return: 'Rien à rapporter à la boutique' }
const OUTCOME_LABEL = { delivered: 'Livrée', failed: 'Échec', returned: 'Rapportée', picked_up: 'Récupérée' }
</script>

<template>
  <div class="space-y-3">
    <Alert v-if="error">{{ error }}</Alert>

    <!-- Jour affiché : aujourd'hui par défaut ; hier pour revoir, demain pour s'organiser -->
    <div class="flex items-center gap-2 rounded-xl bg-white p-2 shadow-sm">
      <button type="button" class="h-11 w-11 shrink-0 rounded-lg bg-slate-100 text-xl" aria-label="Jour précédent" @click="goTo(addDays(day, -1))">‹</button>
      <button type="button" class="min-w-0 flex-1 text-center leading-tight" @click="datePicker?.showPicker?.() ?? datePicker?.click()">
        <span class="block font-display text-lg font-bold">{{ dayName(day) }}</span>
        <span class="block text-xs text-slate-500">{{ shortDay(day) }} · changer 📅</span>
      </button>
      <input ref="datePicker" type="date" class="sr-only" :value="day" aria-label="Choisir un jour" @change="goTo($event.target.value)" />
      <button type="button" class="relative h-11 w-11 shrink-0 rounded-lg bg-slate-100 text-xl" aria-label="Jour suivant" @click="goTo(addDays(day, 1))">
        ›<span v-if="isToday && hasTomorrow" class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-emerald-600" aria-hidden="true" />
      </button>
      <button v-if="!isToday" type="button" class="shrink-0 rounded-lg px-2 py-2 text-sm font-semibold text-emerald-700" @click="goTo(today())">Aujourd'hui</button>
    </div>

    <!-- Bilan du jour -->
    <div v-if="summary" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-1 text-sm text-slate-600">
      <span>Livrées <strong class="text-slate-900">{{ summary.delivered }}</strong></span>
      <span>Échecs <strong class="text-slate-900">{{ summary.failed }}</strong></span>
      <span>Encaissé <strong class="text-slate-900">{{ money(summary.collected) }}</strong></span>
      <RouterLink v-if="isToday && me" to="/livreur/caisse" class="ml-auto font-semibold" :class="me.cash_limit && me.cash_held > me.cash_limit ? 'text-amber-700' : 'text-emerald-700'">{{ money(me.cash_held) }} à verser ›</RouterLink>
    </div>

    <p v-if="isPast" class="rounded-xl bg-sky-50 p-3 text-sm text-sky-900">Journée passée : lecture seule. Une erreur ? Signalez-la au marchand.</p>
    <p v-else-if="!isToday" class="rounded-xl bg-sky-50 p-3 text-sm text-sky-900">
      Prévu {{ isTomorrow ? 'demain' : `le ${shortDay(day)}` }} : pour vous organiser. Les courses passent dans « Aujourd'hui » le matin même.<template v-if="isTomorrow"> Vous pouvez déjà récupérer les colis ce soir.</template>
    </p>

    <button v-if="isToday" type="button" class="block w-full rounded-xl bg-white py-2.5 text-sm font-bold shadow-sm" @click="scanning = true">📷 Scanner un colis</button>
    <QrScanner v-if="scanning" title="Vise le QR du colis" @result="scanned" @close="scanning = false" />

    <!-- Nouvelles courses à accepter un autre jour -->
    <button v-for="d in [...new Set(offersElsewhere.map(dueDay))]" :key="`elsewhere-${d}`" type="button" class="block w-full rounded-xl border-2 border-amber-400 bg-amber-50 px-4 py-2.5 text-left text-sm font-semibold text-amber-900" @click="goTo(d)">
      🔔 {{ offersOn(d) > 1 ? `${offersOn(d)} nouvelles courses` : '1 nouvelle course' }} à accepter · {{ dayName(d).toLowerCase() }} ›
    </button>

    <div v-if="storeNames.length > 1" class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1 text-sm font-semibold">
      <button type="button" class="shrink-0 rounded-full px-3 py-1.5" :class="!storeFilter ? 'bg-slate-900 text-white' : 'border border-field bg-white text-slate-700'" @click="storeFilter = ''">Toutes</button>
      <button v-for="n in storeNames" :key="n" type="button" class="shrink-0 rounded-full px-3 py-1.5" :class="storeFilter === n ? 'bg-slate-900 text-white' : 'border border-field bg-white text-slate-700'" @click="storeFilter = n">{{ n }}</button>
    </div>

    <!-- Les trois onglets du jour -->
    <div class="grid grid-cols-3 gap-1 rounded-xl bg-slate-200 p-1 text-sm font-semibold" role="tablist">
      <button v-for="[k, label] in TABS" :key="k" type="button" role="tab" :aria-selected="tab === k" class="rounded-lg px-1 py-1.5 leading-tight"
              :class="tab === k ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600'" @click="pick(k)">
        <span class="block font-display text-lg" :class="tab === k ? 'text-emerald-700' : 'text-slate-800'">{{ lists[k].length }}</span>{{ label }}
      </button>
    </div>
    <Alert v-if="notice" tone="success">{{ notice }}</Alert>

    <p v-if="isPast && history === null && !error" class="p-6 text-center text-sm text-slate-500">Chargement…</p>

    <!-- À ramasser : actions groupées -->
    <section v-if="tab === 'pickup' && toPickUp.length" class="rounded-xl bg-white p-3 shadow-sm">
      <p class="mb-2 text-sm text-slate-600"><strong>{{ toPickUp.length }} colis</strong> à récupérer à la boutique · {{ money(toCollect(toPickUp)) }} à encaisser</p>
      <button v-if="!confirmingPickUp" class="btn-brand w-full text-sm" :disabled="busy" @click="confirmingPickUp = true">📦 {{ toPickUp.length > 1 ? `J'ai récupéré les ${toPickUp.length} colis` : 'J\'ai récupéré le colis' }}</button>
      <div v-else class="grid grid-cols-2 gap-2">
        <button class="btn-secondary text-sm" @click="confirmingPickUp = false">Annuler</button>
        <button class="btn-brand text-sm" :disabled="busy" @click="pickUpAll">Oui, je les ai</button>
      </div>
    </section>

    <!-- À livrer aujourd'hui : itinéraire et ordre de la tournée -->
    <section v-if="tab === 'deliver' && isToday && lists.deliver.length" class="space-y-2 rounded-xl bg-white p-3 shadow-sm">
      <p class="text-sm text-slate-600"><strong>{{ lists.deliver.length }} arrêt(s)</strong> · {{ money(toCollect(lists.deliver)) }} à encaisser</p>
      <div class="grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1 text-sm font-semibold" role="group" aria-label="Affichage">
        <button type="button" class="rounded-md py-1.5" :class="view === 'list' ? 'bg-white shadow-sm' : 'text-slate-600'" :aria-pressed="view === 'list'" @click="view = 'list'">☰ Liste</button>
        <button type="button" class="rounded-md py-1.5" :class="view === 'map' ? 'bg-white shadow-sm' : 'text-slate-600'" :aria-pressed="view === 'map'" @click="view = 'map'; editing = false">🗺️ Carte</button>
      </div>
      <div v-if="!editing && view === 'list'" class="grid grid-cols-2 gap-2">
        <a :href="itinerary" target="_blank" class="btn-brand px-2 text-sm">🧭 Google Maps</a>
        <button class="btn-secondary px-2 text-sm" @click="edit">↕ Changer l'ordre</button>
      </div>
      <div v-else-if="editing" class="grid grid-cols-2 gap-2">
        <button class="btn-secondary px-2 text-sm" :disabled="busy" @click="auto">✨ Ordre automatique</button>
        <button class="btn-brand px-2 text-sm" @click="saveOrder">✓ Enregistrer</button>
        <button class="btn-ghost col-span-2 text-sm" @click="editing = false">Annuler</button>
      </div>
      <p v-if="lists.deliver.length > 10 && !editing" class="text-xs text-slate-500">L'itinéraire montre les dix premiers arrêts.</p>
    </section>

    <!-- Carte de la tournée : départ, arrêts numérotés dans l'ordre, dernier arrêt entouré -->
    <template v-if="tab === 'deliver' && isToday && view === 'map' && lists.deliver.length">
      <div class="h-[55vh] min-h-72">
        <MapView :points="mapPoints" line :selected="selectedStop" @select="(id) => (selectedStop = id === 'start' ? null : id)" />
      </div>
      <p class="flex flex-wrap gap-x-3 px-1 text-xs text-slate-500">
        <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-green-600 align-[-1px]" />{{ here ? 'Vous' : 'Boutique' }}</span>
        <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-emerald-700 align-[-1px]" />Position exacte</span>
        <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded-full border border-dashed border-emerald-700 align-[-1px]" />Centre du quartier</span>
        <span>Traits à vol d'oiseau</span>
      </p>
      <section v-if="selected" class="space-y-2 rounded-xl bg-white p-3 shadow-sm">
        <p class="flex items-center gap-2">
          <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-emerald-700 text-sm font-bold text-white">{{ selected.n }}</span>
          <strong class="min-w-0 flex-1 truncate">{{ selected.m.place || 'Quartier ?' }}</strong>
          <span v-if="distanceTo(selected.m)" class="text-xs text-slate-500">≈ {{ distanceTo(selected.m) }} km</span>
        </p>
        <p class="text-sm text-slate-600">{{ selected.m.customer_name || 'Client' }} · {{ selected.m.reference }} · {{ money(selected.m.cod_amount) }} à encaisser</p>
        <p v-if="!selected.m.point.exact" class="text-xs text-amber-800">≈ Position approximative : centre du quartier.</p>
        <div class="grid grid-cols-2 gap-2">
          <RouterLink :to="`/livreur/missions/${selected.m.id}`" class="btn-secondary px-2 text-sm">Ouvrir la course</RouterLink>
          <a :href="goUrl(selected.m)" target="_blank" class="btn-brand px-2 text-sm">🧭 Y aller</a>
        </div>
      </section>
      <p v-else class="px-1 text-sm text-slate-500">Touchez un numéro pour voir la course.</p>
      <div v-if="noPoint.length" class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-3 text-sm text-slate-600">
        <p class="font-medium">{{ noPoint.length }} arrêt(s) sans position sur la carte</p>
        <RouterLink v-for="{ m, n } in noPoint" :key="`np-${m.id}`" :to="`/livreur/missions/${m.id}`" class="block truncate text-emerald-700">{{ n }} · {{ m.place || 'Quartier ?' }} · {{ m.customer_name }}</RouterLink>
      </div>
    </template>

    <!-- Chez le livreur, mais au marchand de décider -->
    <template v-if="tab === 'deliver'">
      <RouterLink v-for="m in overdueInHand" :key="`overdue-${m.id}`" :to="`/livreur/missions/${m.id}`" class="block rounded-xl border border-dashed border-slate-300 bg-white px-4 py-3 text-sm text-slate-600">
        ⏸ Colis en main, prévu {{ shortDay(dueDay(m)) }} : le marchand va choisir une nouvelle date. <span class="text-slate-400">{{ m.reference }} · {{ m.place }}</span>
      </RouterLink>
      <RouterLink v-for="m in awaitingDecision" :key="`decision-${m.id}`" :to="`/livreur/missions/${m.id}`" class="block rounded-xl border border-dashed border-slate-300 bg-white px-4 py-3 text-sm text-slate-600">
        ⏸ Échec : le marchand décide (relivrer ou rapporter le colis). <span class="text-slate-400">{{ m.reference }} · {{ m.place }}</span>
      </RouterLink>
    </template>

    <p v-if="(missions || isPast) && !shown.length && (!isPast || history)" class="rounded-xl bg-white p-6 text-center text-slate-500 shadow-sm">
      {{ EMPTY[tab] }} {{ isToday ? 'aujourd\'hui' : 'ce jour-là' }}.
    </p>

    <template v-for="(m, i) in (tab === 'deliver' && isToday && view === 'map' ? [] : shown)" :key="m.id">
      <!-- Nouvelle course à accepter -->
      <section v-if="!isPast && isOffer(m)" class="space-y-2 rounded-xl border-2 border-amber-400 bg-amber-50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">🔔 Nouvelle course à accepter</p>
        <RouterLink :to="`/livreur/missions/${m.id}`" class="block">
          <p class="font-semibold">{{ m.expedition_city ? `📦 Gare · vers ${m.expedition_city}` : m.place || 'Quartier ?' }}<template v-if="m.address && !m.expedition_city"> · {{ m.address }}</template></p>
          <p class="text-sm text-slate-600"><span v-if="m.store" class="mr-1 rounded bg-sky-50 px-1.5 text-xs font-medium text-sky-800">{{ m.store }}</span>{{ m.customer_name || 'Client' }} · {{ m.reference }} · {{ money(m.cod_amount) }} à encaisser</p>
          <p v-if="m.answer_by" class="text-xs text-amber-800">Répondez avant {{ clock(m.answer_by) }}, sinon elle sera confiée à un autre livreur.</p>
        </RouterLink>
        <template v-if="declining?.id === m.id">
          <div class="flex flex-wrap gap-2">
            <button v-for="r in REASONS" :key="r" type="button" class="rounded-full border px-3 py-1.5 text-sm" :class="reason === r ? 'border-emerald-600 bg-white font-medium' : 'border-field'" @click="reason = r">{{ r }}</button>
          </div>
          <input v-model="reason" class="input" maxlength="255" placeholder="Motif du refus" />
          <div class="grid grid-cols-2 gap-2">
            <button class="btn-secondary" @click="declining = null">Annuler</button>
            <button class="btn-danger" :disabled="busy || !reason || !offline.online" @click="decline">Refuser</button>
          </div>
        </template>
        <div v-else class="grid grid-cols-2 gap-2">
          <button class="btn-secondary" :disabled="busy || !offline.online" @click="declining = m; reason = ''">✕ Refuser</button>
          <button class="btn-brand" :disabled="busy || !offline.online" @click="accept(m)">✓ Accepter</button>
        </div>
      </section>

      <template v-else>
        <h2 v-if="tab === 'deliver' && isToday && startsArea(shown, i)" class="pt-1 text-sm font-semibold text-slate-500">{{ m.commune || 'Autres quartiers' }}</h2>
        <div class="flex gap-3 rounded-xl bg-white p-4 shadow-sm active:bg-slate-50" :class="{ 'cursor-pointer': !editing, 'opacity-80': isPast }" @click="!editing && router.push(`/livreur/missions/${m.id}`)">
          <span v-if="tab === 'deliver' && isToday" class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-emerald-700 text-sm font-bold text-white">{{ i + 1 }}</span>
          <span class="min-w-0 flex-1">
            <span class="flex items-center gap-2">
              <span class="font-semibold">{{ m.expedition_city ? `📦 Gare · vers ${m.expedition_city}` : m.place || 'Quartier ?' }}</span>
              <span v-if="isPast" class="ml-auto rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">{{ OUTCOME_LABEL[m.outcome] }}</span>
              <StateBadge v-else kind="shipment" :status="m.status" :label="m.status_label" class="ml-auto" />
            </span>
            <span class="mt-1 block text-sm text-slate-600"><span v-if="m.store" class="mr-1 rounded bg-sky-50 px-1.5 text-xs font-medium text-sky-800">{{ m.store }}</span>{{ m.customer_name || 'Client' }} · {{ m.reference }}</span>
            <span v-if="m.address" class="block truncate text-sm text-slate-500">{{ m.address }}</span>
            <span v-if="m.pending_sync" class="mt-1 block text-xs text-amber-800">⏳ Pas encore envoyé</span>
            <span v-if="tab === 'return'" class="mt-1 block text-sm font-medium text-red-700">↩ À rapporter à la boutique</span>
            <span v-else-if="isPast && m.outcome === 'delivered'" class="mt-1 block text-sm">Encaissé : <strong>{{ money(m.collected_amount ?? 0) }}</strong></span>
            <span v-else class="mt-1 block text-sm">À encaisser : <strong>{{ money(m.cod_amount) }}</strong><span v-if="m.attempts_count" class="text-amber-700"> · {{ m.attempts_count }} tentative(s)</span></span>
            <span v-if="m.customer_response === 'unavailable' && !isPast" class="mt-1 block text-sm font-medium text-amber-800">⚠ Client pas disponible : appelez-le</span>
          </span>
          <span v-if="editing" class="flex flex-col gap-1">
            <button class="btn-secondary px-3 py-1" :disabled="i === 0" aria-label="Monter" @click.stop="move(i, -1)">↑</button>
            <button class="btn-secondary px-3 py-1" :disabled="i === shown.length - 1" aria-label="Descendre" @click.stop="move(i, 1)">↓</button>
          </span>
          <!-- Appel direct du client, sans ouvrir la livraison -->
          <a v-else-if="m.customer_phone && !isPast" :href="`tel:${m.customer_phone}`" class="flex h-12 w-12 shrink-0 items-center justify-center self-center rounded-full bg-emerald-50 text-xl"
             :aria-label="`Appeler ${m.customer_name || 'le client'}`" @click.stop>📞</a>
        </div>
      </template>
    </template>

    <template v-if="tab === 'deliver' && doneToday.length && !editing">
      <h2 class="pt-2 text-sm font-semibold text-slate-500">Terminées aujourd'hui</h2>
      <RouterLink v-for="m in doneToday" :key="m.id" :to="`/livreur/missions/${m.id}`" class="flex items-center gap-2 rounded-xl bg-white px-4 py-3 text-sm opacity-70 shadow-sm">
        <span class="flex-1"><template v-if="m.store">{{ m.store }} · </template>{{ m.place }} · {{ m.customer_name }}</span>
        <StateBadge kind="shipment" :status="m.status" :label="m.status_label" />
      </RouterLink>
    </template>
  </div>
</template>
