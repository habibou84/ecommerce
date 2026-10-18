<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import Alert from '../../components/Alert.vue'
import { errorMessage } from '../../lib/http'
import { cachedGet, loadMissions, offline, savedRoute, saveRoute, sendStep } from '../../lib/courierOffline'
import { money } from '../../lib/format'
import { autoOrder, currentPosition, isLater, itineraryUrl, routeOrder } from '../../lib/route'
import { SHIPMENT_TONES } from '../../lib/shipping'

const missions = ref(null)
const me = ref(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const editing = ref(false) // le livreur change l'ordre de sa tournée
const draft = ref([]) // ordre en cours de modification
let timer

async function load() {
  try {
    const [m, p] = await Promise.all([loadMissions(), cachedGet('/courier/me', 'me')])
    missions.value = m
    me.value = p
    error.value = ''
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(() => {
  load()
  timer = setInterval(() => !editing.value && load(), 60000) // nouvelles missions
})
onUnmounted(() => clearInterval(timer))
watch(() => offline.synced, () => !editing.value && load())

const FINAL = ['delivered', 'returned', 'cancelled', 'lost']
const active = computed(() => (missions.value ?? []).filter((m) => !FINAL.includes(m.status)))
// Tournée d'aujourd'hui (les colis à rapporter à la boutique restent à la fin), puis les missions reportées à plus tard
const stops = computed(() => {
  const today = active.value.filter((m) => !isLater(m))
  const ordered = routeOrder(today.filter((m) => m.status !== 'returning'), savedRoute())
  return [...ordered, ...today.filter((m) => m.status === 'returning')]
})
const later = computed(() => active.value.filter(isLater))
const done = computed(() => (missions.value ?? []).filter((m) => FINAL.includes(m.status)))
const shown = computed(() => (editing.value ? draft.value : stops.value))
const toPickUp = computed(() => stops.value.filter((m) => m.status === 'awaiting_pickup'))
const toDeliver = computed(() => stops.value.filter((m) => m.status !== 'awaiting_pickup' && m.status !== 'returning'))
const toCollect = computed(() => stops.value.reduce((sum, m) => sum + (m.status === 'returning' ? 0 : Math.min(m.cod_amount, m.amount_due ?? m.cod_amount)), 0))
// Itinéraire : les colis déjà en main, sinon toute la tournée
const itinerary = computed(() => itineraryUrl(toDeliver.value.length ? toDeliver.value : stops.value))

// Nouvelle commune dans la liste : un titre la sépare de la précédente
const startsArea = (list, i) => i === 0 || (list[i].commune ?? '') !== (list[i - 1].commune ?? '')

async function pickUpAll() {
  if (!confirm(`Vous avez bien récupéré les ${toPickUp.value.length} colis ?`)) return
  busy.value = true
  error.value = ''
  let queued = 0
  try {
    for (const m of toPickUp.value) if (await sendStep(m, 'in_transit')) queued++
    notice.value = queued ? `${toPickUp.value.length} colis récupérés. Envoi à la boutique dès que le réseau revient.` : 'Colis récupérés ✓'
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
      <RouterLink to="/livreur/argent" class="rounded-xl bg-white p-3 shadow-sm" :class="{ 'ring-2 ring-amber-400': me.cash_limit && me.cash_held > me.cash_limit }">
        <p class="text-base font-bold">{{ money(me.cash_held) }}</p><p class="text-xs text-slate-500">à verser</p>
      </RouterLink>
    </div>

    <section v-if="stops.length" class="space-y-2 rounded-xl bg-white p-3 shadow-sm">
      <p class="text-sm text-slate-600">Ma tournée : <strong>{{ stops.length }} arrêt(s)</strong> · {{ money(toCollect) }} à encaisser</p>
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

    <p v-if="missions && !stops.length" class="rounded-xl bg-white p-6 text-center text-slate-500 shadow-sm">Aucune livraison en attente. Les nouvelles missions apparaissent ici.</p>
    <template v-for="(m, i) in shown" :key="m.id">
      <h2 v-if="startsArea(shown, i)" class="pt-1 text-sm font-semibold text-slate-500">{{ m.status === 'returning' ? 'À rapporter' : m.commune || 'Autres quartiers' }}</h2>
      <component :is="editing ? 'div' : 'RouterLink'" :to="editing ? undefined : `/livreur/missions/${m.id}`" class="flex gap-3 rounded-xl bg-white p-4 shadow-sm active:bg-slate-50">
        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-emerald-700 text-sm font-bold text-white">{{ i + 1 }}</span>
        <span class="min-w-0 flex-1">
          <span class="flex items-center gap-2">
            <span class="font-semibold">{{ m.place || 'Quartier ?' }}</span>
            <span class="ml-auto rounded-full px-2 py-0.5 text-xs font-medium" :class="SHIPMENT_TONES[m.status]">{{ m.status_label }}</span>
          </span>
          <span class="mt-1 block text-sm text-slate-600"><span v-if="m.store" class="mr-1 rounded bg-sky-50 px-1.5 text-xs font-medium text-sky-800">{{ m.store }}</span>{{ m.customer_name || 'Client' }} · {{ m.reference }}</span>
          <span v-if="m.address" class="block truncate text-sm text-slate-500">{{ m.address }}</span>
          <span v-if="m.pending_sync" class="mt-1 block text-xs text-amber-800">⏳ Pas encore envoyé</span>
          <span class="mt-1 block text-sm">À encaisser : <strong>{{ money(m.cod_amount) }}</strong><span v-if="m.attempts_count" class="text-amber-700"> · {{ m.attempts_count }} tentative(s)</span></span>
          <span v-if="m.customer_response === 'unavailable'" class="mt-1 block text-sm font-medium text-amber-800">⚠ Client pas disponible : appelez-le</span>
        </span>
        <span v-if="editing" class="flex flex-col gap-1">
          <button class="btn-secondary px-3 py-1" :disabled="i === 0" aria-label="Monter" @click="move(i, -1)">↑</button>
          <button class="btn-secondary px-3 py-1" :disabled="i === shown.length - 1" aria-label="Descendre" @click="move(i, 1)">↓</button>
        </span>
      </component>
    </template>

    <template v-if="later.length && !editing">
      <h2 class="pt-2 text-sm font-semibold text-slate-500">Reportées à plus tard</h2>
      <RouterLink v-for="m in later" :key="m.id" :to="`/livreur/missions/${m.id}`" class="flex items-center gap-2 rounded-xl bg-white px-4 py-3 text-sm shadow-sm">
        <span class="flex-1">{{ m.place }} · {{ m.customer_name }}</span>
        <span class="text-xs text-slate-500">{{ new Date(m.rescheduled_to).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' }) }}</span>
      </RouterLink>
    </template>

    <template v-if="done.length && !editing">
      <h2 class="pt-2 text-sm font-semibold text-slate-500">Terminées aujourd'hui</h2>
      <RouterLink v-for="m in done" :key="m.id" :to="`/livreur/missions/${m.id}`" class="flex items-center gap-2 rounded-xl bg-white px-4 py-3 text-sm opacity-70 shadow-sm">
        <span class="flex-1"><template v-if="m.store">{{ m.store }} · </template>{{ m.place }} · {{ m.customer_name }}</span>
        <span class="rounded-full px-2 py-0.5 text-xs" :class="SHIPMENT_TONES[m.status]">{{ m.status_label }}</span>
      </RouterLink>
    </template>
  </div>
</template>
