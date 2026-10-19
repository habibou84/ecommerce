<script setup>
import Icon from '../../../components/Icon.vue'
import { computed, onMounted, reactive, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import Pagination from '../../../components/Pagination.vue'
import StockTabs from '../../../components/StockTabs.vue'
import VariantLines from '../../../components/VariantLines.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDate, formatDateTime, money } from '../../../lib/format'
import { PAYMENT_METHODS } from '../../../lib/orders'

// Bons de commande aux fournisseurs : préparer (quantités conseillées), envoyer par WhatsApp, réceptionner, payer
const orders = ref([])
const meta = ref(null)
const locations = ref([])
const suppliers = ref([])
const editing = ref(null)
const detail = ref(null)
const receiving = ref(null)
const paying = reactive({ open: false, amount: null, method: 'cash' })
const supplierForm = reactive({ open: false, id: null, name: '', phone: '', note: '', error: '' })
const error = ref('')
const busy = ref(false)
const TONES = { draft: 'bg-slate-100 text-slate-700', sent: 'bg-sky-100 text-sky-800', partial: 'bg-amber-100 text-amber-800', received: 'bg-emerald-100 text-emerald-800', cancelled: 'bg-slate-100 text-slate-500' }

async function load(page = 1) {
  const { data } = await http.get('/stock/purchase-orders', { params: { page } })
  orders.value = data.data
  meta.value = data.meta
}

async function loadSuppliers() {
  suppliers.value = (await http.get('/stock/suppliers')).data.data
}

onMounted(async () => {
  locations.value = (await http.get('/stock/locations')).data.data.filter((x) => x.is_active)
  await Promise.all([load(), loadSuppliers()])
})

function edit(order = null) {
  error.value = ''
  editing.value = reactive(order
    ? { id: order.id, supplier_id: order.supplier?.id ?? null, supplier_name: '', location_id: order.location.id, expected_on: order.expected_on ?? '', note: order.note ?? '', items: order.items.map((i) => ({ variant_id: i.variant_id, title: i.title, quantity: i.quantity, unit_cost: i.unit_cost ?? '' })) }
    : { id: null, supplier_id: null, supplier_name: '', location_id: locations.value[0]?.id, expected_on: '', note: '', items: [] })
}

async function suggest() {
  const list = (await http.get('/stock/purchase-orders/suggestions')).data.data
  for (const s of list) {
    if (!editing.value.items.some((i) => i.variant_id === s.variant_id)) {
      editing.value.items.push({ variant_id: s.variant_id, title: `${s.title} (${s.sold_30d} vendus en 30 j, ${s.available} dispo.)`, quantity: s.quantity, unit_cost: s.unit_cost ?? '' })
    }
  }
  if (!list.length) error.value = 'Aucun article à recommander pour l\'instant.'
}

async function save() {
  busy.value = true
  error.value = ''
  const e = editing.value
  const payload = {
    supplier_id: e.supplier_id || null, supplier_name: e.supplier_id ? null : e.supplier_name || null, location_id: e.location_id,
    expected_on: e.expected_on || null, note: e.note || null,
    items: e.items.map((i) => ({ variant_id: i.variant_id, quantity: i.quantity, unit_cost: i.unit_cost === '' ? null : Number(i.unit_cost) })),
  }
  try {
    const saved = (await (e.id ? http.put(`/stock/purchase-orders/${e.id}`, payload) : http.post('/stock/purchase-orders', payload))).data.data
    editing.value = null
    await Promise.all([load(), loadSuppliers()])
    detail.value = saved
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    busy.value = false
  }
}

async function show(order) {
  error.value = ''
  detail.value = (await http.get(`/stock/purchase-orders/${order.id}`)).data.data
}

async function act(action, body = undefined) {
  error.value = ''
  busy.value = true
  try {
    const data = (await http.post(`/stock/purchase-orders/${detail.value.id}/${action}`, body)).data.data
    detail.value = data
    if (action === 'send') {
      if (data.whatsapp_url) window.open(data.whatsapp_url, '_blank')
      else await navigator.clipboard?.writeText(data.text).catch(() => {})
    }
    await load(meta.value?.current_page ?? 1)
    return true
  } catch (e) {
    error.value = errorMessage(e)
    return false
  } finally {
    busy.value = false
  }
}

function startReceive() {
  receiving.value = detail.value.items.map((i) => ({ variant_id: i.variant_id, title: i.title, quantity: Math.max(0, i.quantity - i.received_quantity), unit_cost: i.unit_cost ?? '' }))
}

async function saveReceive() {
  if (await act('receive', { items: receiving.value.map((l) => ({ variant_id: l.variant_id, quantity: Number(l.quantity) || 0, unit_cost: l.unit_cost === '' ? null : Number(l.unit_cost) })) })) receiving.value = null
}

function startPay() {
  Object.assign(paying, { open: true, amount: Math.max(0, detail.value.total_cost - detail.value.paid_amount) || null, method: 'cash' })
}

async function savePay() {
  if (await act('pay', { amount: paying.amount, method: paying.method })) paying.open = false
}

function editSupplier(s = null) {
  Object.assign(supplierForm, s ? { open: true, id: s.id, name: s.name, phone: s.phone_display ?? '', note: s.note ?? '', error: '' } : { open: true, id: null, name: '', phone: '', note: '', error: '' })
}

async function saveSupplier() {
  supplierForm.error = ''
  try {
    const body = { name: supplierForm.name, phone: supplierForm.phone || null, note: supplierForm.note || null }
    await (supplierForm.id ? http.put(`/stock/suppliers/${supplierForm.id}`, body) : http.post('/stock/suppliers', body))
    supplierForm.open = false
    await loadSuppliers()
  } catch (e) {
    supplierForm.error = errorMessage(e)
  }
}

const editingTotal = computed(() => (editing.value?.items ?? []).reduce((s, i) => s + (Number(i.unit_cost) || 0) * (Number(i.quantity) || 0), 0))
</script>

<template>
  <div class="space-y-4">
    <StockTabs />
    <div class="flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm text-slate-600">Préparez vos commandes aux fournisseurs, envoyez-les par WhatsApp, puis réceptionnez la marchandise : elle entre en stock.</p>
      <div class="flex gap-2">
        <button class="btn-secondary" @click="editSupplier()">+ Fournisseur</button>
        <button class="btn-brand" @click="edit()">+ Bon de commande</button>
      </div>
    </div>

    <div class="card">
      <ul class="divide-y divide-slate-100">
        <li v-for="o in orders" :key="o.id">
          <button class="flex w-full items-center gap-3 px-4 py-3 text-left text-sm hover:bg-slate-50" @click="show(o)">
            <span class="min-w-0 flex-1">
              <span class="block font-medium">{{ o.reference }} · {{ o.supplier?.name ?? 'Sans fournisseur' }} <span class="ml-1 rounded px-2 py-0.5 text-xs" :class="TONES[o.status]">{{ o.status_label }}</span></span>
              <span class="block text-slate-500">{{ formatDateTime(o.created_at) }} · {{ o.location.name }}<template v-if="o.expected_on"> · attendu le {{ formatDate(o.expected_on) }}</template></span>
            </span>
            <span class="text-right">
              <span class="block font-medium">{{ o.received_units }} / {{ o.units }} reçu(s)</span>
              <span class="block text-slate-500">{{ money(o.total_cost) }}<template v-if="o.paid_amount"> · payé {{ money(o.paid_amount) }}</template></span>
            </span>
          </button>
        </li>
        <li v-if="!orders.length" class="px-4 py-6 text-center text-sm text-slate-500">Aucun bon de commande.</li>
      </ul>
    </div>
    <Pagination :meta="meta" @page="load" />

    <details v-if="suppliers.length" class="card p-4 text-sm">
      <summary class="cursor-pointer font-semibold">Fournisseurs ({{ suppliers.length }})</summary>
      <ul class="mt-2 divide-y divide-slate-100">
        <li v-for="s in suppliers" :key="s.id" class="flex items-center gap-2 py-2">
          <span class="flex-1">{{ s.name }}<span v-if="s.phone_display" class="text-slate-500"> · {{ s.phone_display }}</span><span v-if="s.orders_count" class="text-slate-400"> · {{ s.orders_count }} commande(s)</span></span>
          <button class="text-emerald-700" @click="editSupplier(s)">Modifier</button>
        </li>
      </ul>
    </details>

    <Modal v-if="editing" :title="editing.id ? 'Bon de commande' : 'Nouveau bon de commande'" wide @close="editing = null">
      <form class="space-y-4" @submit.prevent="save">
        <Alert v-if="error">{{ error }}</Alert>
        <div class="grid gap-4 sm:grid-cols-3">
          <Field id="po-supplier" label="Fournisseur">
            <select id="po-supplier" v-model="editing.supplier_id" class="input">
              <option :value="null">Nouveau ou sans fournisseur</option>
              <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <input v-if="!editing.supplier_id" v-model="editing.supplier_name" class="input mt-2" maxlength="120" placeholder="Nom du nouveau fournisseur" />
          </Field>
          <Field id="po-location" label="À livrer à">
            <select id="po-location" v-model="editing.location_id" class="input"><option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option></select>
          </Field>
          <Field id="po-date" label="Livraison souhaitée"><input id="po-date" v-model="editing.expected_on" type="date" class="input" /></Field>
        </div>
        <button type="button" class="btn-secondary" @click="suggest"><Icon name="sparkles" :size="16" class="mr-1 inline-block align-[-3px]" />Ajouter les articles à recommander</button>
        <VariantLines :lines="editing.items" show-cost />
        <Field id="po-note" label="Note pour le fournisseur"><input id="po-note" v-model="editing.note" class="input" maxlength="500" /></Field>
        <p class="text-sm">Total : <strong>{{ money(editingTotal) }}</strong></p>
        <button class="btn-brand w-full" :disabled="busy || !editing.items.length">Enregistrer</button>
      </form>
    </Modal>

    <Modal v-if="detail && !editing" :title="`${detail.reference} · ${detail.supplier?.name ?? 'Sans fournisseur'}`" wide @close="detail = null; receiving = null">
      <div class="space-y-3 text-sm">
        <Alert v-if="error">{{ error }}</Alert>
        <p><span class="rounded px-2 py-0.5 text-xs" :class="TONES[detail.status]">{{ detail.status_label }}</span> · {{ detail.location.name }}<template v-if="detail.expected_on"> · attendu le {{ formatDate(detail.expected_on) }}</template></p>
        <template v-if="!receiving">
          <ul class="divide-y divide-slate-100">
            <li v-for="i in detail.items" :key="i.variant_id" class="flex justify-between gap-3 py-2">
              <span>{{ i.title }}</span>
              <span>{{ i.received_quantity }} / {{ i.quantity }} <span v-if="i.unit_cost !== null" class="text-slate-500">× {{ money(i.unit_cost) }}</span></span>
            </li>
          </ul>
          <p>Total : <strong>{{ money(detail.total_cost) }}</strong> · payé {{ money(detail.paid_amount) }}</p>
          <p v-if="detail.note" class="text-slate-600">{{ detail.note }}</p>
          <div class="flex flex-wrap gap-2">
            <button v-if="['draft', 'sent'].includes(detail.status)" class="btn-secondary" @click="edit(detail)">Modifier</button>
            <button v-if="['draft', 'sent', 'partial'].includes(detail.status)" class="btn-secondary" :disabled="busy" @click="act('send')">{{ detail.supplier?.phone ? 'Envoyer par WhatsApp' : 'Copier le texte' }}</button>
            <button v-if="['draft', 'sent', 'partial'].includes(detail.status)" class="btn-brand" @click="startReceive">Réceptionner</button>
            <button v-if="detail.status !== 'cancelled' && detail.paid_amount < detail.total_cost" class="btn-secondary" @click="startPay">Payer</button>
            <button v-if="['draft', 'sent'].includes(detail.status)" class="btn-ghost text-red-600" :disabled="busy" @click="act('cancel')">Annuler</button>
          </div>
        </template>
        <form v-else class="space-y-3" @submit.prevent="saveReceive">
          <p class="text-slate-600">Quantités reçues aujourd'hui (le reste pourra être reçu plus tard) :</p>
          <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
            <li v-for="l in receiving" :key="l.variant_id" class="flex flex-wrap items-center gap-2 px-3 py-2">
              <span class="min-w-0 flex-1">{{ l.title }}</span>
              <label class="flex items-center gap-1 text-slate-500">Reçu <input v-model.number="l.quantity" type="number" min="0" class="input w-20 py-1.5" /></label>
              <label class="flex items-center gap-1 text-slate-500">Coût/u <input v-model="l.unit_cost" type="number" min="0" class="input w-28 py-1.5" /></label>
            </li>
          </ul>
          <div class="flex gap-2">
            <button type="button" class="btn-secondary flex-1" @click="receiving = null">Retour</button>
            <button class="btn-brand flex-1" :disabled="busy">Entrer en stock</button>
          </div>
        </form>
      </div>
    </Modal>

    <Modal v-if="paying.open" title="Paiement au fournisseur" @close="paying.open = false">
      <form class="space-y-4" @submit.prevent="savePay">
        <Alert v-if="error">{{ error }}</Alert>
        <Field id="pay-amount" label="Montant"><input id="pay-amount" v-model.number="paying.amount" type="number" min="1" class="input text-lg" required /></Field>
        <Field id="pay-method" label="Payé en" hint="Noté dans vos dépenses (Achat de marchandises) ; en espèces, il sort de la caisse.">
          <select id="pay-method" v-model="paying.method" class="input"><option v-for="m in PAYMENT_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option></select>
        </Field>
        <button class="btn-brand w-full" :disabled="busy">Enregistrer le paiement</button>
      </form>
    </Modal>

    <Modal v-if="supplierForm.open" :title="supplierForm.id ? 'Fournisseur' : 'Nouveau fournisseur'" @close="supplierForm.open = false">
      <form class="space-y-4" @submit.prevent="saveSupplier">
        <Alert v-if="supplierForm.error">{{ supplierForm.error }}</Alert>
        <Field id="sup-name" label="Nom"><input id="sup-name" v-model="supplierForm.name" class="input" maxlength="120" required /></Field>
        <Field id="sup-phone" label="Téléphone (WhatsApp)"><input id="sup-phone" v-model="supplierForm.phone" class="input" inputmode="tel" /></Field>
        <Field id="sup-note" label="Note"><input id="sup-note" v-model="supplierForm.note" class="input" maxlength="1000" /></Field>
        <button class="btn-brand w-full">Enregistrer</button>
      </form>
    </Modal>
  </div>
</template>
