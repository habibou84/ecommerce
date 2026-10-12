<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'
import { formatDate, money } from '../../lib/format'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const PERIODS = [
  { value: 'today', label: "Aujourd'hui" },
  { value: 'week', label: '7 jours' },
  { value: 'month', label: 'Ce mois' },
  { value: '30d', label: '30 jours' },
]

const period = ref('today')
const data = ref(null)
const error = ref('')
const showCredit = ref(false)

async function load() {
  error.value = ''
  try {
    data.value = (await http.get('/cash-overview', { params: { period: period.value } })).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

function choose(value) {
  period.value = value
  load()
}

const signed = (amount) => (amount > 0 ? '+' : '') + money(amount)
</script>

<template>
  <div class="space-y-5">
    <Alert v-if="error">{{ error }}</Alert>

    <!-- Où est l'argent en ce moment -->
    <section class="space-y-3">
      <h2 class="text-lg font-semibold">Où est mon argent</h2>
      <div v-if="data" class="grid grid-cols-2 gap-3">
        <RouterLink to="/gestion/livreurs/caisse" class="card block p-4">
          <p class="text-sm text-slate-600">Chez mes livreurs (espèces)</p>
          <p class="whitespace-nowrap text-xl font-semibold sm:text-2xl">{{ money(data.position.couriers_cash) }}</p>
          <p class="text-xs text-slate-500">{{ data.position.couriers_cash_count }} livreur(s) à faire verser</p>
        </RouterLink>
        <component :is="auth.can('carriers.manage') ? 'RouterLink' : 'div'" to="/gestion/expeditions/reversements" class="card block p-4">
          <p class="text-sm text-slate-600">Chez les transporteurs</p>
          <p class="whitespace-nowrap text-xl font-semibold sm:text-2xl" :class="data.position.carriers_due < 0 ? 'text-red-700' : ''">{{ money(data.position.carriers_due) }}</p>
          <p v-if="data.position.carriers_due_overdue" class="text-xs text-red-700">dont {{ money(data.position.carriers_due_overdue) }} en retard</p>
          <p v-else class="text-xs text-slate-500">Livré, pas encore reversé</p>
        </component>
        <div class="card p-4" :class="{ 'col-span-2': showCredit }">
          <button class="block w-full text-left" :disabled="!data.position.customers_due_count" @click="showCredit = !showCredit">
            <p class="text-sm text-slate-600">Clients : reste à payer</p>
            <p class="whitespace-nowrap text-xl font-semibold sm:text-2xl">{{ money(data.position.customers_due) }}</p>
            <p class="text-xs text-slate-500">{{ data.position.customers_due_count }} commande(s) livrée(s) pas soldée(s)</p>
          </button>
          <ul v-if="showCredit" class="mt-3 divide-y divide-slate-100 border-t border-slate-100 text-sm">
            <li v-for="o in data.position.customers_due_orders" :key="o.id" class="flex items-center gap-2 py-1.5">
              <RouterLink :to="`/gestion/commandes/${o.id}`" class="underline">{{ o.reference }}</RouterLink>
              <span class="flex-1 truncate text-slate-600">{{ o.customer }} · {{ formatDate(o.completed_at) }}</span>
              <span class="font-medium">{{ money(o.due) }}</span>
            </li>
          </ul>
        </div>
        <component :is="auth.can('couriers.manage') ? 'RouterLink' : 'div'" to="/gestion/livreurs" class="card block p-4">
          <p class="text-sm text-slate-600">À payer à mes livreurs</p>
          <p class="whitespace-nowrap text-xl font-semibold sm:text-2xl">{{ money(data.position.couriers_to_pay) }}</p>
          <p class="text-xs text-slate-500">Gains et relevés pas encore payés</p>
        </component>
      </div>
    </section>

    <!-- Entrées et sorties de la période -->
    <section class="space-y-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-lg font-semibold">Entrées et sorties</h2>
        <div class="flex rounded-lg bg-slate-100 p-1 text-sm" role="group" aria-label="Période">
          <button
            v-for="p in PERIODS"
            :key="p.value"
            class="rounded-md px-3 py-1"
            :class="period === p.value ? 'bg-white shadow-sm' : 'text-slate-600'"
            @click="choose(p.value)"
          >
            {{ p.label }}
          </button>
        </div>
      </div>
      <div v-if="data" class="card overflow-hidden text-sm">
        <table class="w-full">
          <thead class="bg-slate-50 text-left text-xs text-slate-500">
            <tr><th class="px-4 py-2">Canal</th><th class="px-2 py-2 text-right">Montant</th><th class="px-4 py-2 text-right">dont espèces</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="l in data.flows.lines" :key="l.key" :class="{ 'text-slate-400': !l.count }">
              <td class="px-4 py-2">{{ l.label }} <span v-if="l.count" class="text-xs text-slate-500">({{ l.count }})</span></td>
              <td class="whitespace-nowrap px-2 py-2 text-right font-medium" :class="l.amount < 0 ? 'text-red-700' : ''">{{ l.amount ? signed(l.amount) : '—' }}</td>
              <td class="whitespace-nowrap px-4 py-2 text-right">{{ l.cash ? signed(l.cash) : '—' }}</td>
            </tr>
          </tbody>
          <tfoot class="border-t-2 border-slate-200 font-semibold">
            <tr>
              <td class="px-4 py-2">Solde de la période</td>
              <td class="whitespace-nowrap px-2 py-2 text-right" :class="data.flows.total < 0 ? 'text-red-700' : 'text-emerald-800'">{{ signed(data.flows.total) }}</td>
              <td class="whitespace-nowrap px-4 py-2 text-right">{{ signed(data.flows.cash) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
      <p class="text-xs text-slate-500">
        Un encaissement de livreur compte quand il est remis à la boutique, celui d'un transporteur quand il est reversé : rien n'est compté deux fois.
        La colonne « espèces » donne ce qui doit être entré dans le tiroir-caisse.
        <template v-if="data?.flows.online_fees"> Frais des paiements en ligne : {{ money(data.flows.online_fees) }}.</template>
      </p>
    </section>
  </div>
</template>
