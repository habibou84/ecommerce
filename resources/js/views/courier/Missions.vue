<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'
import { newMissionAlert } from '../../lib/alertSound'
import { cachedGet, loadMissions, offline, savedRoute, saveRoute, sendStep } from '../../lib/courierOffline'
import { money } from '../../lib/format'
import { autoOrder, currentPosition, dueDay, isLate, isLater, itineraryUrl, routeOrder, shortDay, tomorrow } from '../../lib/route'
import { SHIPMENT_TONES } from '../../lib/shipping'
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

async function load() {
  try {
    const [m, p] = await Promise.all([loadMissions(), cachedGet('/courier/me', 'me')])
    const incoming = m.filter((x) => x.acceptance === 'pending' && x.status === 'awaiting_pickup').map((x) => x.id)
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

const FINAL = ['delivered', 'returned', 'cancelled', 'lost']
// À accepter : nouvelles missions pas encore acceptées (ni colis récupéré)
const offers = computed(() => (missions.value ?? []).filter((m) => m.acceptance === 'pending' && m.status === 'awaiting_pickup'))
const active = computed(() => (missions.value ?? []).filter((m) => !FINAL.includes(m.status) && !offers.value.includes(m)))
// Tournée d'aujourd'hui (les colis à rapporter à la boutique restent à la fin), puis les missions reportées à plus tard
const stops = computed(() => {
  const today = active.value.filter((m) => !isLater(m))
  const ordered = routeOrder(today.filter((m) => m.status !== 'returning'), savedRoute())
  return [...ordered, ...today.filter((m) => m.status === 'returning')]
})
const later = computed(() => active.value.filter(isLater))
// Onglets par jour de livraison : Aujourd'hui (avec les courses en retard), Demain, Plus tard
const day = ref('today')
const tomorrowList = computed(() => later.value.filter((m) => dueDay(m) === tomorrow()))
const laterList = computed(() => later.value.filter((m) => dueDay(m) > tomorrow()).sort((a, b) => dueDay(a).localeCompare(dueDay(b))))
const late = computed(() => stops.value.filter(isLate).length)
const upcoming = computed(() => (day.value === 'tomorrow' ? tomorrowList.value : laterList.value))
watch(day, () => (editing.value = false))
const done = computed(() => (missions.value ?? []).filter((m) => FINAL.includes(m.status)))
const shown = computed(() => (editing.value ? draft.value : stops.value))
const toPickUp = computed(() => stops.value.filter((m) => m.status === 'awaiting_pickup'))
const toDeliver = computed(() => stops.value.filter((m) => m.status !== 'awaiting_pickup' && m.status !== 'returning'))
const toCollect = computed(() => stops.value.reduce((sum, m) => sum + (m.status === 'returning' ? 0 : Math.min(m.cod_amount, m.amount_due ?? m.cod_amount)), 0))
// Itinéraire : les colis déjà en main, sinon toute la tournée
const itinerary = computed(() => itineraryUrl(toDeliver.value.length ? toDeliver.value : stops.value))

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
    notice.value = `Mission ${m.reference} acceptée ✓`
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
    notice.value = 'Mission refusée : le marchand est prévenu.'
    declining.value = null
    reason.value = ''
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function pickUpAll() {
  if (!confirm(`Vous avez bien récupéré les ${toPickUp.value.length} colis ?`)) return
  busy.value = true
  error.value = ''
  let queued = 0
  try {
    for (const m of toPickUp.value) if (await sendStep(m, 'in_transit')) queued++
    notice.value = queued ? `${toPickUp.value.length} colis récupérés. Envoi au marchand dès que le réseau revient.` : 'Colis récupérés ✓'
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
    await load()
  }
}

function edit() {
  draft.value = [...stops.value]
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
</script>

<template>
  <div class="space-y-4">
    <Alert v-if="error">{{ error }}</Alert>
    <div v-if="me" class="grid grid-cols-3 gap-2 text-center">
      <div class="rounded-xl bg-white p-3 shadow-sm"><p class="text-xl font-bold">{{ stops.length }}</p><p class="text-xs text-slate-500">arrêts aujourd'hui</p></div>
      <div class="rounded-xl bg-white p-3 shadow-sm"><p class="text-xl font-bold">{{ me.today.delivered }}</p><p class="text-xs text-slate-500">livrées aujourd'hui</p></div>
      <RouterLink to="/livreur/caisse" class="rounded-xl bg-white p-3 shadow-sm" :class="{ 'ring-2 ring-amber-400': me.cash_limit && me.cash_held > me.cash_limit }">
        <p class="text-base font-bold">{{ money(me.cash_held) }}</p><p class="text-xs text-slate-500">à verser</p>
      </RouterLink>
    </div>

    <!-- Jour de livraison : la tournée d'aujourd'hui, puis ce qui est prévu demain et plus tard -->
    <div class="grid grid-cols-3 gap-1 rounded-xl bg-slate-200 p-1 text-sm font-semibold" role="tablist">
      <button v-for="t in [['today', 'Aujourd\'hui', stops.length], ['tomorrow', 'Demain', tomorrowList.length], ['later', 'Plus tard', laterList.length]]" :key="t[0]"
              type="button" role="tab" :aria-selected="day === t[0]" class="whitespace-nowrap rounded-lg px-1 py-2" :class="day === t[0] ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600'" @click="day = t[0]">
        {{ t[1] }} <span class="ml-0.5 rounded-full px-1.5 text-xs" :class="day === t[0] ? 'bg-emerald-700 text-white' : 'bg-slate-300 text-slate-700'">{{ t[2] }}</span>
      </button>
    </div>

    <section v-for="m in offers" :key="`offer-${m.id}`" class="space-y-2 rounded-xl border-2 border-amber-400 bg-amber-50 p-4 shadow-sm">
      <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">🔔 Nouvelle mission</p>
      <RouterLink :to="`/livreur/missions/${m.id}`" class="block">
        <p class="font-semibold">{{ m.expedition_city ? `📦 Gare · vers ${m.expedition_city}` : m.place || 'Quartier ?' }}<template v-if="m.address && !m.expedition_city"> · {{ m.address }}</template></p>
        <p class="text-sm text-slate-600"><span v-if="m.store" class="mr-1 rounded bg-sky-50 px-1.5 text-xs font-medium text-sky-800">{{ m.store }}</span>{{ m.customer_name || 'Client' }} · {{ m.reference }} · {{ money(m.cod_amount) }} à encaisser</p>
        <p v-if="dueDay(m)" class="text-sm font-semibold" :class="isLate(m) ? 'text-red-700' : 'text-slate-700'">{{ isLate(m) ? 'En retard · prévue ' : 'Prévue ' }}{{ shortDay(dueDay(m)) }}</p>
      </RouterLink>
      <template v-if="declining?.id === m.id">
        <div class="flex flex-wrap gap-2">
          <button v-for="r in REASONS" :key="r" type="button" class="rounded-full border px-3 py-1.5 text-sm" :class="reason === r ? 'border-emerald-600 bg-white font-medium' : 'border-slate-300'" @click="reason = r">{{ r }}</button>
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

    <template v-if="day === 'today'">
    <section v-if="stops.length" class="space-y-2 rounded-xl bg-white p-3 shadow-sm">
      <p class="text-sm text-slate-600">Ma tournée : <strong>{{ stops.length }} arrêt(s)</strong> · {{ money(toCollect) }} à encaisser<template v-if="late"> · <strong class="text-red-700">{{ late }} en retard</strong></template></p>
      <div v-if="!editing" class="grid grid-cols-2 gap-2">
        <a :href="itinerary" target="_blank" class="btn-brand px-2 text-sm">🗺️ Itinéraire</a>
        <button class="btn-secondary px-2 text-sm" @click="edit">↕ Changer l'ordre</button>
        <button v-if="toPickUp.length" class="btn-secondary col-span-2 text-sm" :disabled="busy" @click="pickUpAll">📦 J'ai récupéré les {{ toPickUp.length }} colis</button>
      </div>
      <div v-else class="grid grid-cols-2 gap-2">
        <button class="btn-secondary px-2 text-sm" :disabled="busy" @click="auto">✨ Ordre automatique</button>
        <button class="btn-brand px-2 text-sm" @click="saveOrder">✓ Enregistrer</button>
        <button class="btn-ghost col-span-2 text-sm" @click="editing = false">Annuler</button>
      </div>
      <p v-if="stops.length > 10 && !editing" class="text-xs text-slate-500">L'itinéraire montre les dix premiers arrêts.</p>
    </section>
    <Alert v-if="notice" tone="success">{{ notice }}</Alert>

    <p v-if="missions && !stops.length && !offers.length" class="rounded-xl bg-white p-6 text-center text-slate-500 shadow-sm">Aucune livraison pour aujourd'hui. Les nouvelles missions apparaissent ici.</p>
    <template v-for="(m, i) in shown" :key="m.id">
      <h2 v-if="startsArea(shown, i)" class="pt-1 text-sm font-semibold text-slate-500">{{ m.status === 'returning' ? 'À rapporter' : m.commune || 'Autres quartiers' }}</h2>
      <div class="flex gap-3 rounded-xl bg-white p-4 shadow-sm active:bg-slate-50" :class="{ 'cursor-pointer': !editing }" @click="!editing && router.push(`/livreur/missions/${m.id}`)">
        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-emerald-700 text-sm font-bold text-white">{{ i + 1 }}</span>
        <span class="min-w-0 flex-1">
          <span class="flex items-center gap-2">
            <span class="font-semibold">{{ m.place || 'Quartier ?' }}</span>
            <span class="ml-auto rounded-full px-2 py-0.5 text-xs font-medium" :class="SHIPMENT_TONES[m.status]">{{ m.status_label }}</span>
          </span>
          <span class="mt-1 block text-sm text-slate-600"><span v-if="m.store" class="mr-1 rounded bg-sky-50 px-1.5 text-xs font-medium text-sky-800">{{ m.store }}</span>{{ m.customer_name || 'Client' }} · {{ m.reference }}</span>
          <span v-if="m.address" class="block truncate text-sm text-slate-500">{{ m.address }}</span>
          <span v-if="isLate(m)" class="mt-1 inline-block rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700">En retard · prévue {{ shortDay(dueDay(m)) }}</span>
          <span v-if="m.pending_sync" class="mt-1 block text-xs text-amber-800">⏳ Pas encore envoyé</span>
          <span class="mt-1 block text-sm">À encaisser : <strong>{{ money(m.cod_amount) }}</strong><span v-if="m.attempts_count" class="text-amber-700"> · {{ m.attempts_count }} tentative(s)</span></span>
          <span v-if="m.customer_response === 'unavailable'" class="mt-1 block text-sm font-medium text-amber-800">⚠ Client pas disponible : appelez-le</span>
        </span>
        <span v-if="editing" class="flex flex-col gap-1">
          <button class="btn-secondary px-3 py-1" :disabled="i === 0" aria-label="Monter" @click="move(i, -1)">↑</button>
          <button class="btn-secondary px-3 py-1" :disabled="i === shown.length - 1" aria-label="Descendre" @click="move(i, 1)">↓</button>
        </span>
        <!-- Appel direct du client, sans ouvrir la livraison -->
        <a v-else-if="m.customer_phone" :href="`tel:${m.customer_phone}`" class="flex h-12 w-12 shrink-0 items-center justify-center self-center rounded-full bg-emerald-50 text-xl"
           :aria-label="`Appeler ${m.customer_name || 'le client'}`" @click.stop>📞</a>
      </div>
    </template>

    </template>

    <!-- Demain, plus tard : pour s'organiser ; la course se démarre le jour prévu -->
    <template v-else>
      <p v-if="!upcoming.length" class="rounded-xl bg-white p-6 text-center text-slate-500 shadow-sm">{{ day === 'tomorrow' ? 'Rien de prévu demain pour l\'instant.' : 'Rien de prévu après demain pour l\'instant.' }}</p>
      <template v-else>
        <p class="text-sm text-slate-600"><strong>{{ upcoming.length }} course(s)</strong> · {{ money(upcoming.reduce((sum, m) => sum + m.cod_amount, 0)) }} à encaisser · à faire le jour prévu</p>
        <RouterLink v-for="m in upcoming" :key="m.id" :to="`/livreur/missions/${m.id}`" class="flex items-center gap-3 rounded-xl bg-white px-4 py-3 shadow-sm">
          <span class="min-w-0 flex-1">
            <span class="block font-semibold">{{ m.place || 'Quartier ?' }}</span>
            <span class="block text-sm text-slate-600"><span v-if="m.store" class="mr-1 rounded bg-sky-50 px-1.5 text-xs font-medium text-sky-800">{{ m.store }}</span>{{ m.customer_name || 'Client' }} · {{ money(m.cod_amount) }}</span>
          </span>
          <span class="shrink-0 rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">{{ shortDay(dueDay(m)) }}</span>
        </RouterLink>
      </template>
    </template>

    <template v-if="done.length && !editing && day === 'today'">
      <h2 class="pt-2 text-sm font-semibold text-slate-500">Terminées aujourd'hui</h2>
      <RouterLink v-for="m in done" :key="m.id" :to="`/livreur/missions/${m.id}`" class="flex items-center gap-2 rounded-xl bg-white px-4 py-3 text-sm opacity-70 shadow-sm">
        <span class="flex-1"><template v-if="m.store">{{ m.store }} · </template>{{ m.place }} · {{ m.customer_name }}</span>
        <span class="rounded-full px-2 py-0.5 text-xs" :class="SHIPMENT_TONES[m.status]">{{ m.status_label }}</span>
      </RouterLink>
    </template>
  </div>
</template>
