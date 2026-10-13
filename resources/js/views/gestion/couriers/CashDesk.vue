<script setup>
import { onMounted, reactive, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import CashTabs from '../../../components/CashTabs.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'

const data = ref(null)
const error = ref('')
const current = ref(null)
const collections = ref([])
const form = reactive({ received_amount: 0, method: 'cash', note: '' })
const result = ref(null)

async function load() {
  try {
    data.value = (await http.get('/cash')).data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function open(row) {
  Object.assign(form, { received_amount: row.held, method: 'cash', note: '' })
  collections.value = []
  current.value = row
  result.value = null
  error.value = ''
  collections.value = (await http.get(`/cash/couriers/${row.courier_id}`)).data.data
}

async function remit() {
  try {
    result.value = (await http.post(`/cash/couriers/${current.value.courier_id}/remittances`, form)).data.data
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="space-y-4">
    <CashTabs />
    <p class="text-sm text-slate-600">Espèces encaissées par vos livreurs et pas encore versées. Le paiement mobile money du client va directement à la boutique.</p>
    <Alert v-if="error && !current">{{ error }}</Alert>

    <div v-if="data" class="card">
      <ul class="divide-y divide-slate-100">
        <li v-for="row in data.data" :key="row.courier_id" class="flex items-center gap-3 px-4 py-3 text-sm">
          <span class="min-w-0 flex-1">
            <span class="block font-medium">{{ row.name }}</span>
            <span class="block text-slate-500">{{ row.collections_count }} livraison(s)<template v-if="row.oldest_at"> depuis le {{ formatDateTime(row.oldest_at) }}</template></span>
          </span>
          <span v-if="row.over_limit" class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-900">Plafond dépassé</span>
          <span class="w-28 text-right text-base font-semibold">{{ money(row.held) }}</span>
          <button class="btn-brand px-3 py-1.5" :disabled="!row.held" @click="open(row)">Recevoir</button>
        </li>
        <li v-if="!data.data.length" class="px-4 py-6 text-sm text-slate-500">Aucun livreur.</li>
      </ul>
    </div>

    <section v-if="data?.remittances.length" class="card p-4 text-sm">
      <h3 class="mb-2 font-semibold">Derniers versements</h3>
      <ul class="divide-y divide-slate-100">
        <li v-for="r in data.remittances" :key="r.id" class="flex flex-wrap items-center gap-3 py-2">
          <span class="flex-1">{{ r.courier }} · {{ formatDateTime(r.received_at) }} · reçu par {{ r.received_by }}</span>
          <span>{{ money(r.received_amount) }} / {{ money(r.expected_amount) }}</span>
          <span v-if="r.difference" class="rounded px-2 py-0.5 text-xs" :class="r.difference < 0 ? 'bg-red-100 text-red-800' : 'bg-sky-100 text-sky-800'">
            {{ r.difference < 0 ? 'Manque' : 'Excédent' }} {{ money(Math.abs(r.difference)) }}
          </span>
        </li>
      </ul>
    </section>

    <Modal v-if="current" :title="`Versement de ${current.name}`" @close="current = null">
      <div v-if="result" class="space-y-3 text-sm">
        <Alert :tone="result.difference ? 'warning' : 'success'">
          Reçu {{ money(result.received_amount) }} pour {{ money(result.expected_amount) }} attendus.
          <template v-if="result.difference"> {{ result.difference < 0 ? 'Manque' : 'Excédent' }} : {{ money(Math.abs(result.difference)) }}.</template>
        </Alert>
        <button class="btn-brand w-full" @click="current = null">Fermer</button>
      </div>
      <form v-else class="space-y-4" @submit.prevent="remit">
        <Alert v-if="error">{{ error }}</Alert>
        <ul class="max-h-48 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 text-sm">
          <li v-for="c in collections" :key="c.id" class="flex justify-between px-3 py-1.5"><span>{{ c.reference }} · {{ c.customer }}</span><span>{{ money(c.amount) }}</span></li>
        </ul>
        <p class="text-sm">Attendu : <strong>{{ money(current.held) }}</strong></p>
        <Field id="rm-amount" label="Montant reçu"><input id="rm-amount" v-model.number="form.received_amount" type="number" min="0" class="input text-lg" required /></Field>
        <Field id="rm-method" label="Reçu en">
          <select id="rm-method" v-model="form.method" class="input"><option value="cash">Espèces</option><option value="wave">Wave</option><option value="orange_money">Orange Money</option><option value="mtn_momo">MTN MoMo</option><option value="moov_money">Moov Money</option></select>
        </Field>
        <Field id="rm-note" label="Note"><input id="rm-note" v-model="form.note" class="input" maxlength="255" /></Field>
        <button class="btn-brand w-full">Enregistrer le versement</button>
      </form>
    </Modal>
  </div>
</template>
