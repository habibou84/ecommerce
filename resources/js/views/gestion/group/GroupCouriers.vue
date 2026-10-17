<script setup>
import { onMounted, reactive, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import Modal from '../../../components/Modal.vue'
import http, { errorMessage } from '../../../lib/http'
import { money } from '../../../lib/format'
import GroupTabs from './GroupTabs.vue'

const data = ref(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)
// Fenêtre ouverte : versement ou paie d'une personne
const modal = ref(null)
const form = reactive({ received_amount: 0, method: 'cash', note: '', reference: '' })
const METHODS = { cash: 'Espèces', wave: 'Wave', orange_money: 'Orange Money', mtn_momo: 'MTN MoMo', moov_money: 'Moov Money' }

async function load() {
  try {
    data.value = (await http.get('/my-stores/couriers')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function act(request, done) {
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    const res = await request()
    if (res.data.data?.people) data.value = res.data.data
    else await load()
    notice.value = done ?? ''
    modal.value = null
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

function share(person, store) {
  act(() => http.post(`/my-stores/couriers/${person.courier_id}/stores/${store.id}`), `${person.name} travaille aussi pour ${store.name}.`)
}

function unshare(person, line) {
  if (!window.confirm(`${person.name} ne travaillera plus pour ${line.store_name} ? Ses autres boutiques ne changent pas.`)) return
  act(() => http.delete(`/my-stores/couriers/${person.courier_id}/stores/${line.store_id}`))
}

function open(kind, person) {
  Object.assign(form, { received_amount: person.cash_held, method: kind === 'pay' ? 'wave' : 'cash', note: '', reference: '' })
  modal.value = { kind, person }
}

function submit() {
  const { kind, person } = modal.value
  if (kind === 'remit') {
    act(() => http.post(`/my-stores/couriers/${person.courier_id}/remittances`, { received_amount: Number(form.received_amount), method: form.method, note: form.note || null }),
      `Versement de ${person.name} enregistré et réparti entre ses boutiques.`)
  } else {
    act(() => http.post(`/my-stores/couriers/${person.courier_id}/payouts`, { method: form.method, reference: form.reference || null }), `Paie de ${person.name} réglée pour toutes ses boutiques.`)
  }
}
</script>

<template>
  <div class="space-y-5">
    <GroupTabs />
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="notice" tone="success">{{ notice }}</Alert>
    <p class="text-sm text-slate-600">
      Un livreur peut travailler pour plusieurs de vos boutiques : son application réunit toutes ses livraisons, il verse ses espèces en une fois
      et sa paie se règle en une fois. Chaque boutique garde ses zones et sa règle de paie pour lui (modifiables dans Livraisons › Mes livreurs).
    </p>

    <template v-if="data">
      <p v-if="!data.people.length" class="card p-6 text-center text-sm text-slate-500">Aucun livreur pour l'instant. Ajoutez-en dans Livraisons › Mes livreurs.</p>
      <section v-for="p in data.people" :key="p.key" class="card space-y-3 p-4 text-sm">
        <div class="flex flex-wrap items-baseline gap-2">
          <h3 class="text-base font-semibold">{{ p.name }}</h3>
          <span class="text-slate-500">{{ p.phone_display }}</span>
          <span v-if="!p.has_account" class="rounded bg-amber-50 px-1.5 text-xs text-amber-800">compte pas encore activé</span>
          <span class="ml-auto text-slate-600">{{ p.active_missions }} livraison(s) en cours</span>
        </div>

        <ul class="divide-y divide-slate-100">
          <li v-for="line in p.stores" :key="line.courier_id" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
            <span class="min-w-0 flex-1 font-medium">{{ line.store_name }}<span v-if="line.status === 'invited'" class="ml-1 text-xs font-normal text-amber-800">invité</span></span>
            <span class="text-slate-600">{{ money(line.cash_held) }} à verser · {{ money(line.unpaid) }} de paie</span>
            <button v-if="p.stores.length > 1" class="btn-ghost px-2 text-xs" :disabled="busy" @click="unshare(p, line)">Retirer</button>
          </li>
        </ul>

        <div v-if="p.other_stores.length" class="flex flex-wrap items-center gap-2">
          <span class="text-slate-500">Travaille aussi pour :</span>
          <button v-for="s in p.other_stores" :key="s.id" class="btn-secondary px-2 py-1 text-xs" :disabled="busy || !p.has_account" @click="share(p, s)">+ {{ s.name }}</button>
          <span v-if="!p.has_account" class="text-xs text-slate-500">(après activation de son compte)</span>
        </div>

        <div class="flex flex-wrap gap-2 border-t border-slate-100 pt-3">
          <button class="btn-brand px-3 py-1.5" :disabled="busy || !p.cash_held" @click="open('remit', p)">Recevoir {{ money(p.cash_held) }}</button>
          <button class="btn-secondary px-3 py-1.5" :disabled="busy || p.unpaid <= 0" @click="open('pay', p)">Payer {{ money(Math.max(0, p.unpaid)) }}</button>
        </div>
      </section>
    </template>

    <Modal v-if="modal" :title="modal.kind === 'remit' ? `Versement de ${modal.person.name}` : `Paie de ${modal.person.name}`" @close="modal = null">
      <form class="space-y-3 text-sm" @submit.prevent="submit">
        <template v-if="modal.kind === 'remit'">
          <p class="text-slate-600">Attendu : <strong>{{ money(modal.person.cash_held) }}</strong> pour {{ modal.person.stores.filter((l) => l.cash_held).length }} boutique(s). Le montant reçu est réparti entre elles ; un manquant est réparti de la même façon.</p>
          <label class="block">Montant reçu<input v-model="form.received_amount" type="number" min="0" class="input mt-1" required /></label>
        </template>
        <p v-else class="text-slate-600">Un relevé payé est créé dans chaque boutique pour un total de <strong>{{ money(modal.person.unpaid) }}</strong>.</p>
        <label class="block">Moyen
          <select v-model="form.method" class="input mt-1">
            <option v-for="(label, key) in METHODS" :key="key" :value="key">{{ label }}</option>
            <option v-if="modal.kind === 'pay'" value="bank">Virement</option>
          </select>
        </label>
        <label v-if="modal.kind === 'remit'" class="block">Note<input v-model="form.note" class="input mt-1" maxlength="200" /></label>
        <label v-else class="block">Référence du paiement<input v-model="form.reference" class="input mt-1" maxlength="100" /></label>
        <button class="btn-brand w-full" :disabled="busy">Enregistrer</button>
      </form>
    </Modal>
  </div>
</template>
