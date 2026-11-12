<script setup>
import { onMounted, reactive, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'
import { money } from '../../lib/format'

// Tarifs : un seul solde par boutique (commandes et actions IA). Payantes : les commandes prises en live ou par
// WhatsApp et celles venues d'un boost ; gratuites : boutique en ligne et saisie à la main. 3 packs (IA incluse),
// illimité 30 jours, ce qui est offert au départ, minimum pour ouvrir un live, alerte ; ventes du mois.
const PACKS = [1, 2, 3]
const KEYS = ['paid', 'month_price', 'month_ai', 'welcome_orders', 'welcome_ai', 'boost_weight', 'min_orders', 'alert_at',
  ...PACKS.flatMap((i) => [`pack${i}_orders`, `pack${i}_price`, `pack${i}_ai`])]
const data = ref(null)
const form = reactive(Object.fromEntries(KEYS.map((k) => [k, 0])))
const error = ref('')
const saved = ref(false)
const busy = ref(false)

const fill = () => Object.assign(form, Object.fromEntries(KEYS.map((k) => [k, data.value[k]])))
async function load() {
  try {
    data.value = (await http.get('/console/tarifs')).data.data
    fill()
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function save() {
  if (form.paid && !data.value.paid && !window.confirm('Allumer les commandes payantes ? Chaque boutique reçoit ce qui est offert au départ, puis chaque commande en live, par WhatsApp ou venue d\'un boost est retirée de son solde.')) return
  busy.value = true
  error.value = ''
  try {
    data.value = (await http.put('/console/tarifs', { ...form })).data.data
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
      <Alert v-if="!data.provider_ready" tone="warning">PayDunya n'est pas prêt : ses clés ne sont pas sur le serveur. Les packs ne peuvent pas encore être payés.</Alert>
      <section class="card space-y-3 p-4">
        <label class="flex items-center gap-3 text-[15px] font-bold">
          <input v-model="form.paid" type="checkbox" class="h-5 w-5" />
          Commandes payantes (live, WhatsApp, boost)
          <span class="text-sm font-normal text-slate-500">(éteint : rien n'est compté)</span>
        </label>
        <p class="text-sm text-slate-500">Boutique en ligne et commandes saisies à la main : toujours gratuites.</p>

        <h3 class="pt-1 font-bold">Packs (IA incluse)</h3>
        <div v-for="i in PACKS" :key="i" class="grid grid-cols-3 gap-2">
          <label class="text-sm font-semibold">Pack {{ i }} : commandes<input v-model.number="form[`pack${i}_orders`]" type="number" min="1" class="input mt-1" /></label>
          <label class="text-sm font-semibold">Prix (F)<input v-model.number="form[`pack${i}_price`]" type="number" min="0" class="input mt-1" /></label>
          <label class="text-sm font-semibold">Actions IA<input v-model.number="form[`pack${i}_ai`]" type="number" min="0" class="input mt-1" /></label>
        </div>
        <p class="text-xs text-slate-500">Prix par commande : {{ PACKS.map((i) => `${form[`pack${i}_orders`] ? Math.round(form[`pack${i}_price`] / form[`pack${i}_orders`]) : 0} F`).join(' · ') }}. Le pack 2 est conseillé.</p>

        <h3 class="pt-1 font-bold">Illimité 30 jours</h3>
        <div class="grid grid-cols-2 gap-2">
          <label class="text-sm font-semibold">Prix (F)<input v-model.number="form.month_price" type="number" min="100" class="input mt-1" /></label>
          <label class="text-sm font-semibold">Actions IA incluses<input v-model.number="form.month_ai" type="number" min="0" class="input mt-1" /></label>
        </div>

        <h3 class="pt-1 font-bold">Autres réglages</h3>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-sm font-semibold">Commandes offertes au départ<input v-model.number="form.welcome_orders" type="number" min="0" class="input mt-1" /></label>
          <label class="text-sm font-semibold">Actions IA offertes au départ<input v-model.number="form.welcome_ai" type="number" min="0" class="input mt-1" /></label>
          <label class="text-sm font-semibold">Une commande de boost compte pour<input v-model.number="form.boost_weight" type="number" min="1" max="20" class="input mt-1" /></label>
          <label class="text-sm font-semibold">Minimum pour ouvrir un live (commandes)<input v-model.number="form.min_orders" type="number" min="0" class="input mt-1" /></label>
          <label class="text-sm font-semibold">Alerte quand il reste (commandes)<input v-model.number="form.alert_at" type="number" min="0" class="input mt-1" /></label>
        </div>
        <p class="text-sm text-slate-500">Commandes d'un live retirées à la fin du live (une commande annulée ensuite reste comptée, pas une fausse), commandes WhatsApp chaque soir, boosts à chaque relecture. Un live en cours n'est jamais coupé. Recharge par PayDunya (Orange Money, MTN, Moov, Wave, carte). Offrir des commandes, de l'IA ou 30 jours : sur la fiche de la boutique.</p>
        <button type="button" class="btn-brand" :disabled="busy" @click="save">{{ saved ? '✓ Enregistré' : 'Enregistrer' }}</button>
      </section>
      <section class="card p-4">
        <h3 class="font-bold">Ce mois-ci</h3>
        <div class="mt-2 grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
          <div class="rounded-xl bg-slate-50 p-3">Packs vendus<b class="block text-lg">{{ data.month.packs.count }}</b>{{ money(data.month.packs.total) }}</div>
          <div class="rounded-xl bg-slate-50 p-3">Illimités 30 jours<b class="block text-lg">{{ data.month.unlimited.count }}</b>{{ money(data.month.unlimited.total) }}</div>
          <div class="rounded-xl bg-slate-50 p-3">Commandes retirées des soldes<b class="block text-lg">{{ data.month.orders_used }}</b>&nbsp;</div>
        </div>
      </section>
    </template>
  </div>
</template>
