<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import MarketingTabs from '../../../components/MarketingTabs.vue'
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
let timer

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
    <MarketingTabs />
    <Alert v-if="error">{{ error }}</Alert>
    <p v-if="!live" class="text-sm text-slate-500">Chargement…</p>

    <template v-else>
      <section class="card flex flex-wrap items-center justify-between gap-3 p-4">
        <div>
          <h2 class="text-lg font-semibold">{{ live.title }}</h2>
          <p class="text-sm text-slate-500">
            <template v-if="live.status === 'live'"><span class="font-semibold text-red-600">● En direct</span> · page du live : <a :href="pageUrl" target="_blank" rel="noopener" class="underline">{{ pageUrl }}</a></template>
            <template v-else-if="live.status === 'draft'">En préparation : lancez le live au moment où vous passez à l'antenne.</template>
            <template v-else>Terminé le {{ formatDateTime(live.ended_at) }}</template>
          </p>
        </div>
        <button v-if="live.status === 'draft'" class="btn-brand" :disabled="busy" @click="act('start')">Lancer le live</button>
        <button v-else-if="live.status === 'live'" class="btn-secondary" :disabled="busy" @click="end">Terminer le live</button>
      </section>

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
        📷 Montrez le code de l'article à l'écran (carte, ou ce téléphone : touchez le code pour l'afficher en grand) : quand un client envoie sa capture sur WhatsApp, le code est lu et l'article réservé.
      </p>

      <section class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
        <div class="card p-3"><span class="block text-xs text-slate-500">Réservations</span><strong class="text-lg">{{ live.summary.reservations }}</strong></div>
        <div class="card p-3"><span class="block text-xs text-slate-500">Commandes créées</span><strong class="text-lg">{{ live.summary.orders }}</strong></div>
        <div class="card p-3"><span class="block text-xs text-slate-500">Montant</span><strong class="text-lg">{{ money(live.summary.amount) }}</strong></div>
        <div class="card p-3"><span class="block text-xs text-slate-500">Quartier attendu · attente</span><strong class="text-lg">{{ live.summary.pending }} · {{ live.summary.waiting }}</strong></div>
      </section>

      <section class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="i in live.items" :key="i.id" class="card flex items-center gap-3 p-3 text-sm" :class="{ 'opacity-60': i.remaining === 0 }">
          <button type="button" class="grid h-12 w-12 shrink-0 place-items-center rounded-lg bg-red-600 text-lg font-bold text-white" :aria-label="`Afficher ${i.code} en grand`" @click="big = i">{{ i.code }}</button>
          <span class="min-w-0 flex-1">
            <span class="block truncate font-medium">{{ i.title }}</span>
            <span class="text-slate-500">{{ money(i.price) }}</span>
          </span>
          <span class="text-right">
            <strong class="block text-lg" :class="i.remaining ? 'text-emerald-700' : 'text-red-600'">{{ i.remaining ? `${i.remaining} / ${i.quantity}` : 'Épuisé' }}</strong>
            <span v-if="i.waiting" class="text-xs text-sky-700">{{ i.waiting }} en attente</span>
          </span>
        </div>
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
            <span class="min-w-0 flex-1">
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
