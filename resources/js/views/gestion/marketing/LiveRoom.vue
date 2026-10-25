<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import Alert from '../../../components/Alert.vue'
import LiveLink from '../../../components/LiveLink.vue'
import { BEFORE_LIVE, loadChecks, saveChecks } from '../../../lib/liveTraining'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'

// Écran du live : pièces restantes, réservations en direct (page, WhatsApp, équipe), mode assistant, bilan
const props = defineProps({ id: { type: [String, Number], required: true } })
const live = ref(null)
const error = ref('')
const busy = ref(false)
const quick = reactive({ code: '', name: '', phone: '' })
// Code affiché en plein écran : le téléphone posé à côté de l'article pendant le live
const big = ref(null)
// Articles ajoutés en cours de live : recherche dans le catalogue, prix et pièces, puis ajout (codes suivants)
const adding = ref(false)
const extra = ref([])
const search = ref('')
const results = ref([])
const notice = ref('')
let timer
let searchTimer
// « Avant de lancer le live » : cases gardées sur cet appareil, par live
const ready = ref(loadChecks(`live${props.id}`))
watch(ready, (v) => saveChecks(`live${props.id}`, v), { deep: true })

const SOURCE = { page: 'Page du live', whatsapp: 'WhatsApp', manual: 'Équipe' }
const STATUS = {
  reserved: ['Quartier attendu', 'bg-amber-100 text-amber-800'],
  ordered: ['Commande créée', 'bg-emerald-100 text-emerald-800'],
  waiting: ['Liste d\'attente', 'bg-sky-100 text-sky-800'],
  expired: ['Libérée', 'bg-slate-100 text-slate-600'],
  cancelled: ['Annulée', 'bg-slate-100 text-slate-600'],
}
const pageUrl = computed(() => `${window.location.origin}/live`)

async function load() {
  try {
    live.value = (await http.get(`/lives/${props.id}`)).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(() => {
  load()
  timer = setInterval(() => live.value?.status === 'live' && load(), 3000)
})
onUnmounted(() => clearInterval(timer))

async function act(path, body) {
  busy.value = true
  error.value = ''
  try {
    live.value = (await http.post(`/lives/${props.id}/${path}`, body)).data.data
    return true
  } catch (e) {
    error.value = errorMessage(e)
    return false
  } finally {
    busy.value = false
  }
}

function find() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(async () => {
    results.value = (await http.get('/lives/variants', { params: { search: search.value || undefined } }).catch(() => ({ data: { data: [] } }))).data.data
  }, 250)
}

const inLive = (variantId) => live.value?.items.find((i) => i.variant_id === variantId)

function pick(v) {
  if (extra.value.some((i) => i.variant_id === v.id)) return
  extra.value.push({ variant_id: v.id, title: v.title, price: inLive(v.id)?.price ?? v.price, quantity: Math.max(1, Math.min(v.available || 1, 10)), available: v.available })
}

function closeAdding() {
  adding.value = false
  extra.value = []
  search.value = ''
  results.value = []
}

async function addItems() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post(`/lives/${props.id}/items`, {
      items: extra.value.map((i) => ({ variant_id: i.variant_id, price: Number(i.price), quantity: Number(i.quantity) })),
    })
    live.value = data.data
    notice.value = `Ajouté : ${data.meta.codes.join(', ')}. Montrez le code à l'écran, les clients peuvent réserver tout de suite.`
    closeAdding()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function end() {
  if (window.confirm('Terminer le live ? Les clients ne pourront plus réserver ; ceux qui ont une pièce gardée peuvent encore donner leur quartier.')) await act('end')
}

async function addQuick() {
  if (await act('reservations', { code: quick.code, name: quick.name || null, phone: quick.phone || null })) {
    Object.assign(quick, { code: '', name: '', phone: '' })
  }
}
</script>

<template>
  <div class="space-y-4">
    <RouterLink to="/gestion/live" class="text-sm text-emerald-700 hover:underline">← Tous les lives</RouterLink>
    <Alert v-if="error">{{ error }}</Alert>
    <p v-if="notice" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900">{{ notice }}</p>
    <p v-if="!live" class="text-sm text-slate-500">Chargement…</p>

    <template v-else>
      <section class="card flex flex-wrap items-center justify-between gap-3 p-4">
        <div>
          <h2 class="text-lg font-semibold">{{ live.title }}</h2>
          <p class="text-sm text-slate-500">
            <template v-if="live.status === 'live'"><span class="font-semibold text-red-600">● En direct</span> · vos clients réservent avec le lien ci-dessous.</template>
            <template v-else-if="live.status === 'draft'">En préparation : lancez le live au moment où vous passez à l'antenne.</template>
            <template v-else>Terminé le {{ formatDateTime(live.ended_at) }}</template>
          </p>
        </div>
        <button v-if="live.status === 'draft'" class="btn-brand" :disabled="busy" @click="act('start')">Lancer le live</button>
        <button v-else-if="live.status === 'live'" class="btn-secondary" :disabled="busy" @click="end">Terminer le live</button>
      </section>

      <section v-if="live.status === 'draft'" class="card space-y-2 p-4 text-sm">
        <p class="font-semibold">Avant de lancer le live <span class="font-normal text-slate-500">· {{ ready.length }} / {{ BEFORE_LIVE.length }}</span></p>
        <label v-for="c in BEFORE_LIVE" :key="c" class="flex items-start gap-2">
          <input v-model="ready" type="checkbox" :value="c" class="mt-0.5 h-4 w-4 accent-emerald-700" />
          <span :class="{ 'text-slate-400 line-through': ready.includes(c) }">{{ c }}</span>
        </label>
        <RouterLink to="/gestion/live" class="inline-block text-emerald-700 hover:underline">🎓 Revoir les vidéos « Se former au live »</RouterLink>
      </section>

      <LiveLink v-if="live.status !== 'ended'" :url="pageUrl" :compact="live.status === 'live'" />

      <!-- WhatsApp qui répond aux clients : conseils selon le mode -->
      <Alert v-if="live.status !== 'ended' && live.whatsapp === null" tone="warning">
        Aucun WhatsApp n'est relié : les clients peuvent réserver sur la page du live, mais leurs codes et captures envoyés sur WhatsApp ne seront pas lus.
        Reliez votre WhatsApp dans Messages.
      </Alert>
      <p v-else-if="live.status !== 'ended' && live.whatsapp === 'link'" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
        WhatsApp relié par QR code : idéal pour démarrer. Les réponses automatiques partent quelques secondes après chaque message, sans rafale, pour protéger votre numéro.
        Si vous faites de gros lives, passez au WhatsApp officiel (Messages › WhatsApp), sans risque de blocage.
      </p>
      <p v-if="live.status === 'live'" class="rounded-lg bg-slate-50 p-3 text-sm text-slate-700">
        📷 Montrez le code de l'article à l'écran (carte, ou ce téléphone : touchez le code pour l'afficher en grand) : quand un client envoie sa capture sur WhatsApp, le code est lu et l'article réservé. Pour plusieurs pièces, le client écrit par exemple « A2 x3 ».
      </p>

      <section class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
        <div class="card p-3"><span class="block text-xs text-slate-500">Réservations</span><strong class="text-lg">{{ live.summary.reservations }}</strong></div>
        <div class="card p-3"><span class="block text-xs text-slate-500">Commandes créées</span><strong class="text-lg">{{ live.summary.orders }}</strong></div>
        <div class="card p-3"><span class="block text-xs text-slate-500">Montant</span><strong class="text-lg">{{ money(live.summary.amount) }}</strong></div>
        <div class="card p-3"><span class="block text-xs text-slate-500">Quartier attendu · attente</span><strong class="text-lg">{{ live.summary.pending }} · {{ live.summary.waiting }}</strong></div>
      </section>

      <section class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="i in live.items" :key="i.id" class="card flex min-w-0 items-center gap-3 p-3 text-sm" :class="{ 'opacity-60': i.remaining === 0 }">
          <button type="button" class="grid h-12 w-12 shrink-0 place-items-center rounded-lg bg-red-600 text-lg font-bold text-white" :aria-label="`Afficher ${i.code} en grand`" @click="big = i">{{ i.code }}</button>
          <span class="min-w-0 flex-1">
            <span class="block font-medium leading-snug [overflow-wrap:anywhere]">{{ i.title }}</span>
            <span class="text-slate-500">{{ money(i.price) }}</span>
          </span>
          <span class="shrink-0 text-right">
            <strong class="block text-lg" :class="i.remaining ? 'text-emerald-700' : 'text-red-600'">{{ i.remaining ? `${i.remaining} / ${i.quantity}` : 'Épuisé' }}</strong>
            <span v-if="i.waiting" class="text-xs text-sky-700">{{ i.waiting }} en attente</span>
          </span>
        </div>
      </section>

      <section v-if="live.status !== 'ended'" class="card space-y-3 p-3 text-sm">
        <button v-if="!adding" type="button" class="btn-secondary" @click="adding = true; notice = ''; find()">+ Ajouter des articles</button>
        <template v-else>
          <p class="font-semibold">Ajouter des articles{{ live.status === 'live' ? ' pendant le live' : '' }}</p>
          <p class="text-xs text-slate-500">Les codes déjà annoncés ne changent pas : un nouvel article prend le code suivant. Un article déjà dans le live reçoit les pièces en plus.</p>
          <div v-for="(i, index) in extra" :key="i.variant_id" class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 p-2">
            <span class="min-w-0 flex-1 basis-[calc(100%-3rem)] font-medium [overflow-wrap:anywhere] sm:basis-0">
              {{ i.title }}
              <span class="block text-xs font-normal text-slate-500">{{ inLive(i.variant_id) ? `Déjà dans le live (${inLive(i.variant_id).code}) : pièces en plus` : `${i.available} en stock` }}</span>
            </span>
            <button type="button" class="btn-ghost order-last px-2 text-red-600 max-sm:order-none" aria-label="Retirer" @click="extra.splice(index, 1)">✕</button>
            <label v-if="!inLive(i.variant_id)" class="flex items-center gap-1">Prix <input v-model="i.price" type="number" min="0" class="input w-28" /></label>
            <label class="flex items-center gap-1">Pièces <input v-model="i.quantity" type="number" min="1" class="input w-20" /></label>
          </div>
          <input v-model="search" class="input" placeholder="Chercher un article (nom ou référence)" @input="find" />
          <div v-if="results.length" class="max-h-72 divide-y divide-slate-100 overflow-auto rounded-lg border border-slate-200">
            <button v-for="v in results" :key="v.id" type="button" class="flex w-full items-center gap-3 p-2 text-left hover:bg-slate-50" @click="pick(v)">
              <img v-if="v.image" :src="v.image" alt="" class="h-9 w-9 rounded object-cover" />
              <span class="min-w-0 flex-1">{{ v.title }}<span v-if="inLive(v.id)" class="ml-1 text-xs text-red-600">{{ inLive(v.id).code }}</span></span>
              <span class="text-slate-500">{{ money(v.price) }} · {{ v.available }} en stock</span>
            </button>
          </div>
          <div class="flex gap-2">
            <button type="button" class="btn-ghost" @click="closeAdding">Annuler</button>
            <button type="button" class="btn-brand" :disabled="busy || !extra.length" @click="addItems">Ajouter au live</button>
          </div>
        </template>
      </section>

      <form v-if="live.status === 'live'" class="card flex flex-wrap items-end gap-2 p-3 text-sm" @submit.prevent="addQuick">
        <p class="w-full font-semibold">Mode assistant : une réservation vue dans les commentaires</p>
        <label class="block w-24"><span class="label">Code</span><input v-model="quick.code" class="input uppercase" required maxlength="4" placeholder="A3" /></label>
        <label class="block min-w-[8rem] flex-1"><span class="label">Pseudo ou nom</span><input v-model="quick.name" class="input" maxlength="120" /></label>
        <label class="block min-w-[8rem] flex-1"><span class="label">Numéro (facultatif)</span><input v-model="quick.phone" class="input" type="tel" /></label>
        <button class="btn-brand" :disabled="busy">Réserver</button>
        <p class="w-full text-xs text-slate-500">Avec un numéro, le client reçoit un WhatsApp qui lui demande son quartier (si votre WhatsApp est relié).</p>
      </form>

      <section class="card overflow-hidden">
        <h3 class="border-b border-slate-100 p-3 text-sm font-semibold">Réservations en direct</h3>
        <p v-if="!live.reservations.length" class="p-4 text-sm text-slate-500">Elles s'afficheront ici dès les premières.</p>
        <ul class="divide-y divide-slate-100 text-sm">
          <li v-for="r in live.reservations" :key="r.id" class="flex flex-wrap items-center gap-2 p-3">
            <span class="grid h-8 w-8 shrink-0 place-items-center rounded bg-red-600 text-xs font-bold text-white">{{ r.code }}</span>
            <span class="min-w-0 flex-1 [overflow-wrap:anywhere]">
              <span class="font-medium">{{ r.customer_name || r.customer_phone || 'Client' }}</span>
              <span class="text-slate-500"> · {{ SOURCE[r.source] }}<template v-if="r.shipping_address"> · {{ r.shipping_address }}</template></span>
            </span>
            <span class="rounded-full px-2 py-0.5 text-xs" :class="STATUS[r.status][1]">{{ STATUS[r.status][0] }}</span>
            <RouterLink v-if="r.order" :to="`/gestion/commandes/${r.order.id}`" class="text-emerald-700 underline">{{ r.order.reference }}</RouterLink>
            <button v-if="['reserved', 'waiting'].includes(r.status)" class="btn-ghost px-2 py-1 text-xs text-red-600" :disabled="busy" @click="act(`reservations/${r.id}/cancel`)">Annuler</button>
          </li>
        </ul>
      </section>
    </template>

    <button v-if="big" type="button" class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4 bg-red-600 text-white" @click="big = null">
      <span class="text-[38vmin] font-black leading-none">{{ big.code }}</span>
      <span class="text-[7vmin] font-bold">{{ money(big.price) }}</span>
      <span class="text-sm opacity-80">Touchez pour fermer</span>
    </button>
  </div>
</template>
