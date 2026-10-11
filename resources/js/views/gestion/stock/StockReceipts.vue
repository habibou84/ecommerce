<script setup>
import { onMounted, reactive, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import Pagination from '../../../components/Pagination.vue'
import StockTabs from '../../../components/StockTabs.vue'
import VariantLines from '../../../components/VariantLines.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'

const receipts = ref([])
const meta = ref(null)
const locations = ref([])
const suppliers = ref([])
const creating = ref(null)
const detail = ref(null)
const error = ref('')
const busy = ref(false)

async function load(page = 1) {
  const { data } = await http.get('/stock/receipts', { params: { page } })
  receipts.value = data.data
  meta.value = data.meta
}

onMounted(async () => {
  const [l, s] = await Promise.all([http.get('/stock/locations'), http.get('/stock/suppliers')])
  locations.value = l.data.data.filter((x) => x.is_active)
  suppliers.value = s.data.data
  await load()
})

function create() {
  error.value = ''
  creating.value = reactive({ location_id: locations.value[0]?.id, supplier_name: '', reference: '', items: [] })
}

async function save() {
  busy.value = true
  error.value = ''
  try {
    await http.post('/stock/receipts', {
      ...creating.value,
      items: creating.value.items.map((i) => ({ variant_id: i.variant_id, quantity: i.quantity, unit_cost: i.unit_cost === '' ? null : Number(i.unit_cost) })),
    })
    creating.value = null
    suppliers.value = (await http.get('/stock/suppliers')).data.data
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function show(receipt) {
  detail.value = (await http.get(`/stock/receipts/${receipt.id}`)).data.data
}
</script>

<template>
  <div class="space-y-4">
    <StockTabs />
    <div class="flex items-center justify-between gap-3">
      <p class="text-sm text-slate-600">Marchandise reçue d'un fournisseur ou de fabrication : elle entre en stock et met à jour le coût moyen.</p>
      <button class="btn-brand shrink-0" @click="create">+ Réception</button>
    </div>

    <div class="card">
      <ul class="divide-y divide-slate-100">
        <li v-for="r in receipts" :key="r.id">
          <button class="flex w-full items-center gap-3 px-4 py-3 text-left text-sm hover:bg-slate-50" @click="show(r)">
            <span class="min-w-0 flex-1">
              <span class="block font-medium">{{ r.supplier ?? 'Sans fournisseur' }} <span v-if="r.reference" class="text-slate-400">· {{ r.reference }}</span></span>
              <span class="block text-slate-500">{{ formatDateTime(r.received_at) }} · {{ r.location }} · {{ r.author }}</span>
            </span>
            <span class="text-right">
              <span class="block font-medium">{{ r.units }} article(s)</span>
              <span v-if="r.total_cost !== null" class="block text-slate-500">{{ money(r.total_cost) }}</span>
            </span>
          </button>
        </li>
        <li v-if="!receipts.length" class="px-4 py-6 text-center text-sm text-slate-500">Aucune réception.</li>
      </ul>
    </div>
    <Pagination :meta="meta" @page="load" />

    <Modal v-if="creating" title="Nouvelle réception" wide @close="creating = null">
      <form class="space-y-4" @submit.prevent="save">
        <Alert v-if="error">{{ error }}</Alert>
        <div class="grid gap-4 sm:grid-cols-3">
          <Field id="r-location" label="Emplacement">
            <select id="r-location" v-model="creating.location_id" class="input">
              <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option>
            </select>
          </Field>
          <Field id="r-supplier" label="Fournisseur (facultatif)">
            <input id="r-supplier" v-model="creating.supplier_name" class="input" list="suppliers" maxlength="120" />
            <datalist id="suppliers"><option v-for="s in suppliers" :key="s.id" :value="s.name" /></datalist>
          </Field>
          <Field id="r-reference" label="Référence (bon de livraison)">
            <input id="r-reference" v-model="creating.reference" class="input" maxlength="60" />
          </Field>
        </div>
        <VariantLines :lines="creating.items" show-cost />
        <button class="btn-brand w-full" :disabled="busy || !creating.items.length">Enregistrer la réception</button>
      </form>
    </Modal>

    <Modal v-if="detail" :title="`Réception du ${formatDateTime(detail.received_at)}`" @close="detail = null">
      <p class="text-sm text-slate-600">{{ detail.location }} · {{ detail.supplier ?? 'Sans fournisseur' }} {{ detail.reference ? `· ${detail.reference}` : '' }}</p>
      <ul class="mt-3 divide-y divide-slate-100 text-sm">
        <li v-for="item in detail.items" :key="item.variant" class="flex justify-between gap-3 py-2">
          <span>{{ item.variant }}</span>
          <span>{{ item.quantity }} <span v-if="item.unit_cost !== null" class="text-slate-500">× {{ money(item.unit_cost) }}</span></span>
        </li>
      </ul>
    </Modal>
  </div>
</template>
