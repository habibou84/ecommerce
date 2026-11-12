<script setup>
import { onMounted, reactive, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'
import { money } from '../../lib/format'

// Lives payants (à part des abonnements) : paliers par nombre de commandes, forfait 30 jours, commandes offertes,
// crédit minimum, actions IA offertes, interrupteur ; ventes du mois
const KEYS = ['paid', 'free_upto', 'tier2_upto', 'tier2_price', 'tier3_upto', 'tier3_price', 'top_price', 'month_price', 'free_orders', 'min_credit', 'free_ai', 'boost_paid', 'boost_price']
const data = ref(null)
const form = reactive(Object.fromEntries(KEYS.map((k) => [k, 0])))
const error = ref('')
const saved = ref(false)
const busy = ref(false)

const fill = () => Object.assign(form, Object.fromEntries(KEYS.map((k) => [k, data.value[k]])))
async function load() {
  try {
    data.value = (await http.get('/console/lives')).data.data
    fill()
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function save() {
  if (form.paid && !data.value.paid && !window.confirm('Allumer les lives payants ? Chaque boutique garde ses commandes offertes, puis paie selon le palier atteint à chaque live.')) return
  busy.value = true
  error.value = ''
  try {
    data.value = (await http.put('/console/lives', { ...form })).data.data
    fill()
    saved.value = true
    setTimeout(() => (saved.value = false), 2000)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="max-w-2xl space-y-4">
    <Alert v-if="error">{{ error }}</Alert>
    <template v-if="data">
      <Alert v-if="!data.provider_ready" tone="warning">PayDunya n'est pas prêt : ses clés ne sont pas sur le serveur. Le crédit live ne peut pas encore être rechargé.</Alert>
      <section class="card space-y-3 p-4">
        <label class="flex items-center gap-3 text-[15px] font-bold">
          <input v-model="form.paid" type="checkbox" class="h-5 w-5" />
          Lives payants
          <span class="text-sm font-normal text-slate-500">(éteint : les lives restent gratuits pour tous)</span>
        </label>
        <h3 class="pt-1 font-bold">Paliers par live (commandes créées pendant le live)</h3>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-sm font-semibold">Gratuit jusqu'à (commandes)<input v-model.number="form.free_upto" type="number" min="0" class="input mt-1" /></label>
          <span class="hidden sm:block" />
          <label class="text-sm font-semibold">Palier 2 : jusqu'à (commandes)<input v-model.number="form.tier2_upto" type="number" min="1" class="input mt-1" /></label>
          <label class="text-sm font-semibold">Prix du palier 2 (F)<input v-model.number="form.tier2_price" type="number" min="0" class="input mt-1" /></label>
          <label class="text-sm font-semibold">Palier 3 : jusqu'à (commandes)<input v-model.number="form.tier3_upto" type="number" min="1" class="input mt-1" /></label>
          <label class="text-sm font-semibold">Prix du palier 3 (F)<input v-model.number="form.tier3_price" type="number" min="0" class="input mt-1" /></label>
          <span class="hidden sm:block" />
          <label class="text-sm font-semibold">Au-delà (F)<input v-model.number="form.top_price" type="number" min="0" class="input mt-1" /></label>
        </div>
        <h3 class="pt-1 font-bold">Autres réglages</h3>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-sm font-semibold">Commandes en live offertes au départ<input v-model.number="form.free_orders" type="number" min="0" class="input mt-1" /></label>
          <label class="text-sm font-semibold">Crédit minimum pour ouvrir un live (F)<input v-model.number="form.min_credit" type="number" min="0" class="input mt-1" /></label>
          <label class="text-sm font-semibold">Lives illimités 30 jours (F)<input v-model.number="form.month_price" type="number" min="100" class="input mt-1" /></label>
          <label class="text-sm font-semibold">Actions IA offertes au 1er live<input v-model.number="form.free_ai" type="number" min="0" class="input mt-1" /></label>
        </div>
        <h3 class="pt-1 font-bold">Assistant Boost (pubs Facebook et Instagram)</h3>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="flex items-center gap-2 text-sm font-semibold"><input v-model="form.boost_paid" type="checkbox" class="h-5 w-5" />Assistant Boost payant</label>
          <label class="text-sm font-semibold">Prix par commande venue d'un boost (F)<input v-model.number="form.boost_price" type="number" min="0" class="input mt-1" /></label>
        </div>
        <p class="text-sm text-slate-500">Le prix du palier est retiré du crédit live à la fin de chaque live ; une commande annulée ensuite reste comptée. Recharge par PayDunya (Orange Money, MTN, Moov, Wave, carte). Commandes, crédit ou 30 jours offerts à une boutique : sur sa fiche.</p>
        <button type="button" class="btn-brand" :disabled="busy" @click="save">{{ saved ? '✓ Enregistré' : 'Enregistrer' }}</button>
      </section>
      <section class="card p-4">
        <h3 class="font-bold">Ce mois-ci</h3>
        <div class="mt-2 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
          <div class="rounded-xl bg-slate-50 p-3">Lives comptés<b class="block text-lg">{{ data.month.lives }}</b>{{ data.month.orders }} commandes</div>
          <div class="rounded-xl bg-slate-50 p-3">Retiré des crédits<b class="block text-lg">{{ money(data.month.billed) }}</b>&nbsp;</div>
          <div class="rounded-xl bg-slate-50 p-3">Recharges<b class="block text-lg">{{ data.month.recharges.count }}</b>{{ money(data.month.recharges.total) }}</div>
          <div class="rounded-xl bg-slate-50 p-3">30 jours<b class="block text-lg">{{ data.month.month.count }}</b>{{ money(data.month.month.total) }}</div>
        </div>
      </section>
    </template>
  </div>
</template>
