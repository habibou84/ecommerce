<script setup>
import { onMounted, reactive, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import Pagination from '../../../components/Pagination.vue'
import StatusBadge from '../../../components/StatusBadge.vue'
import StockTabs from '../../../components/StockTabs.vue'
import VariantLines from '../../../components/VariantLines.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime } from '../../../lib/format'

const transfers = ref([])
const meta = ref(null)
const locations = ref([])
const creating = ref(null)
const detail = ref(null)
const error = ref('')
const busy = ref(false)

async function load(page = 1) {
  const { data } = await http.get('/stock/transfers', { params: { page } })
  transfers.value = data.data
  meta.value = data.meta
}

// Autres boutiques de la même adresse : envoi immédiat
const storeTransfers = ref([])
const otherStores = ref([])
const sending = ref(null)
const sent = ref('')
const openedStoreTransfer = ref(null)

async function loadStoreTransfers() {
  const { data } = await http.get('/stock/store-transfers')
  storeTransfers.value = data.data
  otherStores.value = data.stores
}

function createStoreTransfer() {
  error.value = ''
  sent.value = ''
  sending.value = reactive({ store_id: otherStores.value[0]?.id, location_id: locations.value[0]?.id, note: '', items: [] })
}

async function sendToStore() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post('/stock/store-transfers', { ...sending.value, items: sending.value.items.map((i) => ({ variant_id: i.variant_id, quantity: i.quantity })) })
    sending.value = null
    sent.value = data.message
    await loadStoreTransfers()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  locations.value = (await http.get('/stock/locations')).data.data.filter((x) => x.is_active)
  await Promise.all([load(), loadStoreTransfers()])
})

function create() {
  error.value = ''
  creating.value = reactive({ from_location_id: locations.value[0]?.id, to_location_id: locations.value[1]?.id, reference: '', items: [] })
}

async function save() {
  busy.value = true
  error.value = ''
  try {
    await http.post('/stock/transfers', { ...creating.value, items: creating.value.items.map((i) => ({ variant_id: i.variant_id, quantity: i.quantity })) })
    creating.value = null
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function show(transfer) {
  error.value = ''
  const { data } = await http.get(`/stock/transfers/${transfer.id}`)
  detail.value = { ...data.data, items: data.data.items.map((i) => ({ ...i, received: i.received_quantity ?? i.quantity })) }
}

async function act(action) {
  busy.value = true
  error.value = ''
  try {
    const body = action === 'receive' ? { items: detail.value.items.map((i) => ({ variant_id: i.variant_id, received_quantity: Number(i.received) })) } : {}
    await http.post(`/stock/transfers/${detail.value.id}/${action}`, body)
    detail.value = null
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <StockTabs />
    <div class="flex items-center justify-between gap-3">
      <p class="text-sm text-slate-600">Déplacer de la marchandise d'un emplacement à un autre. Elle quitte le départ à l'envoi et entre à la réception.</p>
      <button class="btn-brand shrink-0" :disabled="locations.length < 2" :title="locations.length < 2 ? 'Créez d\'abord un deuxième emplacement' : ''" @click="create">+ Transfert</button>
    </div>
    <Alert v-if="locations.length < 2" tone="info">Un seul emplacement : ajoutez-en un dans l'onglet « Emplacements » pour faire des transferts.</Alert>

    <div class="card">
      <ul class="divide-y divide-slate-100">
        <li v-for="t in transfers" :key="t.id">
          <button class="flex w-full items-center gap-3 px-4 py-3 text-left text-sm hover:bg-slate-50" @click="show(t)">
            <span class="min-w-0 flex-1">
              <span class="block font-medium">{{ t.from }} → {{ t.to }}</span>
              <span class="block text-slate-500">{{ formatDateTime(t.shipped_at) }} · {{ t.units }} article(s) {{ t.reference ? `· ${t.reference}` : '' }}</span>
            </span>
            <StatusBadge :status="t.status" :label="t.status_label" />
          </button>
        </li>
        <li v-if="!transfers.length" class="px-4 py-6 text-center text-sm text-slate-500">Aucun transfert.</li>
      </ul>
    </div>
    <Pagination :meta="meta" @page="load" />

    <template v-if="otherStores.length">
      <div class="flex items-center justify-between gap-3 pt-2">
        <div>
          <h2 class="font-semibold">Entre vos boutiques</h2>
          <p class="text-sm text-slate-600">Envoi immédiat vers une autre boutique de votre adresse : le stock baisse ici et monte là-bas.</p>
        </div>
        <button class="btn-brand shrink-0" @click="createStoreTransfer">+ Envoyer à une boutique</button>
      </div>
      <Alert v-if="sent" tone="success">{{ sent }}</Alert>
      <div class="card">
        <ul class="divide-y divide-slate-100">
          <li v-for="t in storeTransfers" :key="t.id">
            <button class="flex w-full items-center gap-3 px-4 py-3 text-left text-sm hover:bg-slate-50" @click="openedStoreTransfer = t">
              <span class="min-w-0 flex-1">
                <span class="block font-medium">{{ t.direction === 'out' ? `Envoyé à ${t.to}` : `Reçu de ${t.from}` }}</span>
                <span class="block text-slate-500">{{ formatDateTime(t.created_at) }} · {{ t.quantity }} article(s){{ t.note ? ` · ${t.note}` : '' }}</span>
              </span>
              <span :class="t.direction === 'out' ? 'text-red-600' : 'text-emerald-700'" class="font-medium">{{ t.direction === 'out' ? '−' : '+' }}{{ t.quantity }}</span>
            </button>
          </li>
          <li v-if="!storeTransfers.length" class="px-4 py-6 text-center text-sm text-slate-500">Aucun envoi entre vos boutiques.</li>
        </ul>
      </div>
    </template>

    <Modal v-if="sending" title="Envoyer à une autre boutique" wide @close="sending = null">
      <form class="space-y-4" @submit.prevent="sendToStore">
        <Alert v-if="error">{{ error }}</Alert>
        <div class="grid gap-4 sm:grid-cols-3">
          <Field id="st-store" label="Vers la boutique">
            <select id="st-store" v-model="sending.store_id" class="input">
              <option v-for="s in otherStores" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
          </Field>
          <Field v-if="locations.length > 1" id="st-from" label="Depuis l'emplacement">
            <select id="st-from" v-model="sending.location_id" class="input">
              <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option>
            </select>
          </Field>
          <Field id="st-note" label="Note (facultatif)">
            <input id="st-note" v-model="sending.note" class="input" maxlength="200" />
          </Field>
        </div>
        <p class="text-sm text-slate-500">Un article doit exister dans l'autre boutique : copiez-le d'abord depuis sa fiche produit (« Dans vos autres boutiques »).</p>
        <VariantLines :lines="sending.items" :location-id="sending.location_id" />
        <button class="btn-brand w-full" :disabled="busy || !sending.items.length">Envoyer</button>
      </form>
    </Modal>

    <Modal v-if="openedStoreTransfer" :title="openedStoreTransfer.direction === 'out' ? `Envoyé à ${openedStoreTransfer.to}` : `Reçu de ${openedStoreTransfer.from}`" @close="openedStoreTransfer = null">
      <p class="text-sm text-slate-600">{{ formatDateTime(openedStoreTransfer.created_at) }}{{ openedStoreTransfer.author ? ` · par ${openedStoreTransfer.author}` : '' }}{{ openedStoreTransfer.note ? ` · ${openedStoreTransfer.note}` : '' }}</p>
      <ul class="mt-3 divide-y divide-slate-100 text-sm">
        <li v-for="(line, i) in openedStoreTransfer.lines" :key="i" class="flex justify-between gap-3 py-2">
          <span>{{ line.title }}</span><span>{{ line.quantity }}</span>
        </li>
      </ul>
    </Modal>

    <Modal v-if="creating" title="Nouveau transfert" wide @close="creating = null">
      <form class="space-y-4" @submit.prevent="save">
        <Alert v-if="error">{{ error }}</Alert>
        <div class="grid gap-4 sm:grid-cols-3">
          <Field id="t-from" label="Depuis">
            <select id="t-from" v-model="creating.from_location_id" class="input">
              <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option>
            </select>
          </Field>
          <Field id="t-to" label="Vers">
            <select id="t-to" v-model="creating.to_location_id" class="input">
              <option v-for="l in locations.filter((x) => x.id !== creating.from_location_id)" :key="l.id" :value="l.id">{{ l.name }}</option>
            </select>
          </Field>
          <Field id="t-ref" label="Référence (facultatif)">
            <input id="t-ref" v-model="creating.reference" class="input" maxlength="60" />
          </Field>
        </div>
        <VariantLines :lines="creating.items" :location-id="creating.from_location_id" />
        <button class="btn-brand w-full" :disabled="busy || !creating.items.length">Envoyer</button>
      </form>
    </Modal>

    <Modal v-if="detail" :title="`${detail.from} → ${detail.to}`" @close="detail = null">
      <div class="space-y-4">
        <Alert v-if="error">{{ error }}</Alert>
        <div class="flex items-center gap-2 text-sm text-slate-600">
          <StatusBadge :status="detail.status" :label="detail.status_label" />
          Envoyé le {{ formatDateTime(detail.shipped_at) }}<template v-if="detail.received_at">, reçu le {{ formatDateTime(detail.received_at) }}</template>
        </div>
        <ul class="divide-y divide-slate-100 text-sm">
          <li v-for="item in detail.items" :key="item.variant_id" class="flex items-center justify-between gap-3 py-2">
            <span>{{ item.variant }}</span>
            <span v-if="detail.status === 'in_transit'" class="flex items-center gap-1 text-slate-500">
              reçu <input v-model="item.received" type="number" min="0" :max="item.quantity" class="input w-20 py-1" /> / {{ item.quantity }}
            </span>
            <span v-else>{{ item.received_quantity ?? item.quantity }} / {{ item.quantity }}</span>
          </li>
        </ul>
        <div v-if="detail.status === 'in_transit'" class="flex gap-2">
          <button class="btn-ghost text-red-600" :disabled="busy" @click="act('cancel')">Annuler le transfert</button>
          <button class="btn-brand ml-auto" :disabled="busy" @click="act('receive')">Marquer comme reçu</button>
        </div>
      </div>
    </Modal>
  </div>
</template>
