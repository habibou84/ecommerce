<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import CashTabs from '../../../components/CashTabs.vue'
import http, { download, errorMessage } from '../../../lib/http'
import { formatDate, formatDateTime, money } from '../../../lib/format'
import { PAYMENT_METHODS } from '../../../lib/orders'

const balances = ref(null)
const payouts = ref(null)
const open = ref(null)
const error = ref('')

// Détail des courses à reverser d'une entreprise de livraison (chargé à l'ouverture)
const detail = reactive({ id: null, lines: [], loading: false })

// Saisie d'un reversement reçu d'une entreprise sans API
const entry = ref(null)
const form = reactive({ amount: 0, method: 'cash', transaction_ref: '', selected: [] })
const formError = ref('')
const result = ref(null)

async function load() {
  error.value = ''
  try {
    const [b, p] = await Promise.all([http.get('/carrier-balances'), http.get('/carrier-payouts')])
    balances.value = b.data
    payouts.value = p.data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function toggle(carrier) {
  if (detail.id === carrier.connection_id) {
    detail.id = null
    return
  }
  Object.assign(detail, { id: carrier.connection_id, lines: [], loading: true })
  try {
    detail.lines = (await http.get(`/carrier-balances/${carrier.connection_id}`)).data.data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    detail.loading = false
  }
}

function exportCsv(carrier) {
  download(`/carrier-balances/${carrier.connection_id}/export`, `a-recevoir-${carrier.connection_id}.csv`).catch((e) => (error.value = errorMessage(e)))
}

async function startEntry(carrier) {
  if (detail.id !== carrier.connection_id) await toggle(carrier)
  formError.value = ''
  result.value = null
  Object.assign(form, { method: 'cash', transaction_ref: '', selected: detail.lines.map((l) => l.shipment_id) })
  form.amount = expected.value
  entry.value = carrier
}

const expected = computed(() => detail.lines.filter((l) => form.selected.includes(l.shipment_id)).reduce((sum, l) => sum + l.net, 0))
// Courses cochées ou décochées : le montant reçu proposé suit le net attendu
watch(expected, (value) => (form.amount = value))

async function savePayout() {
  formError.value = ''
  try {
    result.value = (await http.post(`/carriers/${entry.value.connection_id}/payouts`, {
      shipment_ids: form.selected,
      amount: form.amount,
      method: form.method,
      transaction_ref: form.transaction_ref || null,
    })).data.data
    detail.id = null
    await load()
  } catch (e) {
    formError.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="space-y-4">
    <CashTabs />
    <Alert v-if="error">{{ error }}</Alert>

    <!-- Ce que les entreprises de livraison doivent à la boutique -->
    <section class="space-y-3">
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="text-lg font-semibold">À recevoir</h2>
        <p v-if="balances" class="text-sm text-slate-600">Total : <strong class="text-slate-900">{{ money(balances.total) }}</strong></p>
      </div>
      <p class="text-sm text-slate-600">
        Courses livrées pas encore reversées : encaissé par l'entreprise de livraison, moins son tarif. Une commande déjà payée compte en négatif : vous lui devez la livraison.
      </p>
      <p v-if="balances && !balances.data.length" class="card p-6 text-sm text-slate-500">Aucune entreprise de livraison connectée.</p>
      <article v-for="c in balances?.data ?? []" :key="c.connection_id" class="card text-sm">
        <div class="flex flex-wrap items-center gap-3 px-4 py-3">
          <button class="flex flex-1 flex-wrap items-center gap-3 text-left" :disabled="!c.count" @click="toggle(c)">
            <span class="font-medium">{{ c.carrier }}</span>
            <span class="text-slate-500">
              {{ c.count }} course{{ c.count > 1 ? 's' : '' }}<template v-if="c.oldest_delivered_at"> · depuis le {{ formatDate(c.oldest_delivered_at) }}</template>
            </span>
            <span v-if="c.overdue_count" class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800">
              {{ c.overdue_count }} en retard ({{ balances.overdue_days }} j et plus) · {{ money(c.overdue_amount) }}
            </span>
          </button>
          <span class="font-semibold" :class="c.amount < 0 ? 'text-red-700' : ''">{{ money(c.amount) }}</span>
        </div>
        <div v-if="detail.id === c.connection_id" class="border-t border-slate-100">
          <p v-if="detail.loading" class="px-4 py-3 text-slate-500">Chargement…</p>
          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="text-left text-xs text-slate-500">
                <tr><th class="px-4 py-1">Commande</th><th>Livrée</th><th class="text-right">Encaissé</th><th class="text-right">Frais</th><th class="px-4 text-right">Net</th></tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="l in detail.lines" :key="l.shipment_id" :class="{ 'bg-red-50': l.overdue }">
                  <td class="px-4 py-1.5"><RouterLink :to="`/gestion/commandes/${l.order_id}`" class="underline">{{ l.order_reference }}</RouterLink></td>
                  <td>{{ formatDate(l.delivered_at) }} <span class="text-xs text-slate-500">({{ l.days }} j)</span></td>
                  <td class="text-right">{{ money(l.collected) }}</td>
                  <td class="text-right">{{ money(l.fees) }}</td>
                  <td class="px-4 text-right font-medium">{{ money(l.net) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="!detail.loading" class="flex flex-wrap gap-2 px-4 py-3">
            <button class="btn-secondary px-3 py-1.5" @click="exportCsv(c)">Relevé CSV</button>
            <button v-if="!c.is_api" class="btn-brand px-3 py-1.5" @click="startEntry(c)">Enregistrer un reversement</button>
            <p v-else class="self-center text-xs text-slate-500">Les reversements de {{ c.carrier }} arrivent automatiquement.</p>
          </div>
        </div>
      </article>
    </section>

    <!-- Argent reversé -->
    <section class="space-y-3">
      <h2 class="text-lg font-semibold">Argent reversé</h2>
      <p class="text-sm text-slate-600">Chaque reversement est rapproché de vos livraisons : encaissé, moins le tarif annoncé.</p>
      <p v-if="payouts && !payouts.length" class="card p-6 text-sm text-slate-500">Aucun reversement reçu pour l'instant.</p>
      <article v-for="p in payouts ?? []" :key="p.id" class="card text-sm">
        <button class="flex w-full flex-wrap items-center gap-3 px-4 py-3 text-left" @click="open = open === p.id ? null : p.id">
          <span class="font-medium">{{ p.reference }}</span>
          <span class="text-slate-500">{{ p.carrier }} · {{ formatDateTime(p.paid_at) }}</span>
          <span class="ml-auto font-semibold">{{ money(p.amount) }}</span>
          <span class="rounded-full px-2 py-0.5 text-xs" :class="p.status === 'matched' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900'">
            {{ p.status === 'matched' ? 'Conforme' : `Écart ${money(p.difference)}` }}
          </span>
        </button>
        <table v-if="open === p.id" class="w-full border-t border-slate-100 text-sm">
          <thead class="text-left text-xs text-slate-500"><tr><th class="px-4 py-1">Course</th><th>Commande</th><th class="text-right">Encaissé</th><th class="text-right">Frais</th><th class="text-right">Net</th><th class="px-4 text-right">Attendu</th></tr></thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="l in p.lines" :key="l.tracking_code" :class="{ 'bg-amber-50': l.difference }">
              <td class="px-4 py-1.5">{{ l.tracking_code }}</td>
              <td><RouterLink v-if="l.order_id" :to="`/gestion/commandes/${l.order_id}`" class="underline">{{ l.order_reference }}</RouterLink><span v-else class="text-amber-800">Inconnue</span></td>
              <td class="text-right">{{ money(l.collected) }}</td>
              <td class="text-right">{{ money(l.fees) }}</td>
              <td class="text-right">{{ money(l.net) }}</td>
              <td class="px-4 text-right">{{ l.expected_net === null ? '—' : money(l.expected_net) }}</td>
            </tr>
          </tbody>
        </table>
      </article>
    </section>

    <Modal v-if="entry" :title="`Reversement de ${entry.carrier}`" @close="entry = null">
      <div v-if="result" class="space-y-3 text-sm">
        <Alert :tone="result.difference ? 'warning' : 'success'">
          Reversement {{ result.reference }} enregistré : {{ money(result.amount) }}.
          <template v-if="result.difference"> Écart avec le net attendu : {{ money(result.difference) }}.</template>
        </Alert>
        <button class="btn-brand w-full" @click="entry = null">Fermer</button>
      </div>
      <form v-else class="space-y-4" @submit.prevent="savePayout">
        <Alert v-if="formError">{{ formError }}</Alert>
        <ul class="max-h-56 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 text-sm">
          <li v-for="l in detail.lines" :key="l.shipment_id">
            <label class="flex items-center gap-3 px-3 py-1.5">
              <input v-model="form.selected" type="checkbox" :value="l.shipment_id" />
              <span class="flex-1">{{ l.order_reference }} · {{ formatDate(l.delivered_at) }}</span>
              <span>{{ money(l.net) }}</span>
            </label>
          </li>
        </ul>
        <p class="text-sm">Net attendu : <strong>{{ money(expected) }}</strong></p>
        <Field id="cp-amount" label="Montant reçu"><input id="cp-amount" v-model.number="form.amount" type="number" min="0" class="input text-lg" required /></Field>
        <Field id="cp-method" label="Reçu en">
          <select id="cp-method" v-model="form.method" class="input"><option v-for="m in PAYMENT_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option></select>
        </Field>
        <Field id="cp-ref" label="Référence (facultatif)"><input id="cp-ref" v-model="form.transaction_ref" class="input" maxlength="100" /></Field>
        <button class="btn-brand w-full" :disabled="!form.selected.length">Enregistrer le reversement</button>
      </form>
    </Modal>
  </div>
</template>
