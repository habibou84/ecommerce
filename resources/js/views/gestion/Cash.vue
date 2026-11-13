<script setup>
import { computed, onMounted, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import CashDrawer from '../../components/CashDrawer.vue'
import CashTabs from '../../components/CashTabs.vue'
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
  if (!auth.can('finance.view')) return
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

// Total à récupérer : espèces chez les livreurs + argent chez les entreprises de livraison
const toCollect = computed(() => (data.value ? data.value.position.couriers_cash + data.value.position.carriers_due : 0))

const signed = (amount) => (amount > 0 ? '+' : '') + money(amount)
</script>

<template>
  <div class="space-y-6">
    <CashTabs />
    <Alert v-if="error">{{ error }}</Alert>

    <!-- 1. Où est l'argent en ce moment -->
    <section v-if="auth.can('finance.view')" class="space-y-3">
      <h2 class="text-lg font-semibold">Où est mon argent</h2>
      <template v-if="data">
        <div class="card overflow-hidden">
          <div class="p-4">
            <p class="text-sm font-medium text-slate-600">À récupérer</p>
            <p class="whitespace-nowrap text-3xl font-bold tracking-tight">{{ money(toCollect) }}</p>
            <p class="text-sm text-slate-600">chez vos livreurs et les entreprises de livraison</p>
          </div>
          <RouterLink to="/gestion/caisse/livreurs" class="flex min-h-11 items-center gap-3 border-t border-slate-100 px-4 py-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-orange-100 text-orange-800" aria-hidden="true">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12a4 4 0 100-8 4 4 0 000 8zM4 20c0-4 4-6 8-6s8 2 8 6" /></svg>
            </span>
            <span class="min-w-0 flex-1">
              <span class="block font-semibold">Chez mes livreurs</span>
              <span class="block text-xs" :class="data.position.couriers_cash_count ? 'text-orange-800' : 'text-slate-600'">
                <template v-if="data.position.couriers_cash_count">{{ data.position.couriers_cash_count }} livreur(s) à faire verser</template>
                <template v-else>Rien à faire verser</template>
              </span>
            </span>
            <span class="whitespace-nowrap font-bold">{{ money(data.position.couriers_cash) }}</span>
            <span class="text-slate-500" aria-hidden="true">›</span>
          </RouterLink>
          <component :is="auth.can('carriers.manage') ? 'RouterLink' : 'div'" to="/gestion/caisse/a-recevoir" class="flex min-h-11 items-center gap-3 border-t border-slate-100 px-4 py-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sky-100 text-sky-800" aria-hidden="true">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21V5l8-2v18M12 9h8v12M7 9h2M7 13h2M7 17h2M15 13h2M15 17h2" /></svg>
            </span>
            <span class="min-w-0 flex-1">
              <span class="block font-semibold">Chez les entreprises de livraison</span>
              <span v-if="data.position.carriers_due_overdue" class="block text-xs text-red-700">dont {{ money(data.position.carriers_due_overdue) }} en retard</span>
              <span v-else class="block text-xs text-slate-600">Livré, pas encore reversé</span>
            </span>
            <span class="whitespace-nowrap font-bold" :class="data.position.carriers_due < 0 ? 'text-red-700' : ''">{{ money(data.position.carriers_due) }}</span>
            <span v-if="auth.can('carriers.manage')" class="text-slate-500" aria-hidden="true">›</span>
          </component>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div class="card p-4" :class="{ 'col-span-2': showCredit }">
            <button class="block w-full text-left" :disabled="!data.position.customers_due_count" @click="showCredit = !showCredit">
              <p class="text-sm font-medium text-slate-600">Clients : reste à payer</p>
              <p class="whitespace-nowrap text-xl font-bold">{{ money(data.position.customers_due) }}</p>
              <p class="text-xs text-slate-600">{{ data.position.customers_due_count }} commande(s) livrée(s), pas soldée(s)</p>
              <p v-if="data.position.customers_due_count" class="mt-1 text-xs font-medium text-emerald-800">{{ showCredit ? 'Masquer le détail ▴' : 'Voir le détail ▾' }}</p>
            </button>
            <ul v-if="showCredit" class="mt-3 divide-y divide-slate-100 border-t border-slate-100 text-sm">
              <li v-for="o in data.position.customers_due_orders" :key="o.id" class="flex items-center gap-2 py-1.5">
                <RouterLink :to="`/gestion/commandes/${o.id}`" class="underline">{{ o.reference }}</RouterLink>
                <span class="flex-1 truncate text-slate-600">{{ o.customer }} · {{ formatDate(o.completed_at) }}</span>
                <span class="font-medium">{{ money(o.due) }}</span>
              </li>
            </ul>
          </div>
          <component :is="auth.can('couriers.manage') ? 'RouterLink' : 'div'" to="/gestion/livraisons/livreurs" class="card block p-4">
            <p class="text-sm font-medium text-slate-600">À payer à mes livreurs</p>
            <p class="whitespace-nowrap text-xl font-bold">{{ money(data.position.couriers_to_pay) }}</p>
            <p class="text-xs text-slate-600">Gains pas encore payés</p>
          </component>
        </div>
      </template>
    </section>

    <!-- 2. Tiroir-caisse du jour -->
    <CashDrawer @changed="load" />

    <!-- 3. Entrées et sorties de la période -->
    <section v-if="auth.can('finance.view')" class="space-y-3">
      <h2 class="text-lg font-semibold">Entrées et sorties</h2>
      <div class="grid grid-cols-4 gap-0.5 rounded-lg bg-slate-200 p-1 text-center text-sm" role="group" aria-label="Période">
        <button
          v-for="p in PERIODS"
          :key="p.value"
          class="rounded-md px-1 py-2"
          :class="period === p.value ? 'bg-white font-semibold shadow-sm' : 'text-slate-700'"
          :aria-pressed="period === p.value"
          @click="choose(p.value)"
        >
          {{ p.label }}
        </button>
      </div>
      <div v-if="data" class="card overflow-hidden text-sm">
        <ul class="divide-y divide-slate-100">
          <li v-for="l in data.flows.lines" :key="l.key" class="flex items-center gap-2 px-4 py-3" :class="{ 'text-slate-500': !l.count }">
            <span class="min-w-0 flex-1">
              <span class="block">{{ l.label }} <span v-if="l.count" class="text-xs text-slate-600">({{ l.count }})</span></span>
              <span v-if="l.cash" class="block text-xs text-slate-600">dont espèces : {{ signed(l.cash) }}</span>
            </span>
            <span class="whitespace-nowrap font-semibold" :class="l.amount < 0 ? 'text-red-700' : ''">{{ l.amount ? signed(l.amount) : '—' }}</span>
          </li>
        </ul>
        <div class="flex items-center gap-2 bg-emerald-50 px-4 py-3.5">
          <span class="min-w-0 flex-1">
            <span class="block font-bold">Solde de la période</span>
            <span class="block text-xs text-emerald-900">dont espèces : {{ signed(data.flows.cash) }}</span>
          </span>
          <span class="whitespace-nowrap text-lg font-bold" :class="data.flows.total < 0 ? 'text-red-700' : 'text-emerald-800'">{{ signed(data.flows.total) }}</span>
        </div>
      </div>
      <p class="text-xs text-slate-600">
        Chaque somme est comptée une seule fois, le jour où vous la recevez. « Espèces » : l'argent à mettre dans le tiroir.
        <template v-if="data?.flows.online_fees"> Frais des paiements en ligne : {{ money(data.flows.online_fees) }}.</template>
      </p>
    </section>
  </div>
</template>
