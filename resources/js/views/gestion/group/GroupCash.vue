<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import http, { errorMessage } from '../../../lib/http'
import { money } from '../../../lib/format'
import GroupTabs from './GroupTabs.vue'

const data = ref(null)
const error = ref('')
const period = ref('today')
const PERIODS = [['today', 'Aujourd\'hui'], ['week', '7 jours'], ['month', 'Ce mois'], ['30d', '30 jours']]

async function load() {
  error.value = ''
  try {
    data.value = (await http.get('/my-stores/cash', { params: { period: period.value } })).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

const LINES = [
  ['couriers_cash', 'Espèces chez les livreurs'],
  ['carriers_due', 'À recevoir des entreprises de livraison'],
  ['customers_due', 'Clients à crédit'],
  ['couriers_to_pay', 'Paie des livreurs à verser'],
]
</script>

<template>
  <div class="space-y-5">
    <GroupTabs />
    <Alert v-if="error">{{ error }}</Alert>

    <template v-if="data">
      <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div v-for="[key, label] in LINES" :key="key" class="card p-3">
          <p class="text-xs text-slate-500">{{ label }}</p>
          <p class="text-lg font-semibold">{{ money(data.total[key]) }}</p>
          <p v-if="key === 'carriers_due' && data.total.carriers_due_overdue" class="text-xs text-amber-800">dont {{ money(data.total.carriers_due_overdue) }} en retard</p>
        </div>
      </section>

      <section class="card overflow-x-auto p-4 text-sm">
        <h3 class="mb-2 font-semibold">Où est l'argent, boutique par boutique</h3>
        <table class="w-full min-w-[520px]">
          <thead class="text-left text-xs text-slate-500"><tr><th class="py-1">Boutique</th><th v-for="[key, label] in LINES" :key="key" class="text-right">{{ label }}</th></tr></thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="s in data.stores" :key="s.id">
              <td class="py-1.5"><a :href="s.url" class="font-medium text-emerald-800 underline">{{ s.name }}</a></td>
              <td v-for="[key] in LINES" :key="key" class="text-right">{{ money(s[key]) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section class="card space-y-3 p-4 text-sm">
        <div class="flex flex-wrap items-center gap-2">
          <h3 class="mr-auto font-semibold">Entrées et sorties</h3>
          <button v-for="[value, label] in PERIODS" :key="value" class="rounded-full border px-3 py-1"
                  :class="period === value ? 'border-emerald-700 bg-emerald-50 text-emerald-800' : 'border-slate-300 bg-white'"
                  @click="period = value; load()">{{ label }}</button>
        </div>
        <ul class="divide-y divide-slate-100">
          <li v-for="s in data.stores" :key="s.id" class="flex justify-between py-1.5"><span>{{ s.name }}</span><span>{{ money(s.flows_total) }} <span class="text-xs text-slate-500">dont {{ money(s.flows_cash) }} en espèces</span></span></li>
          <li class="flex justify-between py-1.5 font-semibold"><span>Total</span><span>{{ money(data.total.flows_total) }} <span class="text-xs font-normal text-slate-500">dont {{ money(data.total.flows_cash) }} en espèces</span></span></li>
        </ul>
        <p class="text-xs text-slate-500">Le détail de chaque boutique est dans sa propre caisse (cliquez sur son nom).</p>
      </section>
    </template>
  </div>
</template>
