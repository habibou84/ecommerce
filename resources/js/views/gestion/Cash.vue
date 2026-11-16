<script setup>
import { onMounted, ref, watch } from 'vue'
import Alert from '../../components/Alert.vue'
import CashDrawer from '../../components/CashDrawer.vue'
import CashFlowTable from '../../components/CashFlowTable.vue'
import CashTabs from '../../components/CashTabs.vue'
import RecurringExpenses from '../../components/RecurringExpenses.vue'
import http, { errorMessage } from '../../lib/http'
import { money } from '../../lib/format'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const period = ref('today')
const data = ref(null)
const error = ref('')

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
watch(period, load)
</script>

<template>
  <div class="space-y-5">
    <CashTabs />
    <Alert v-if="error">{{ error }}</Alert>

    <CashDrawer @changed="load" />
    <RecurringExpenses v-if="auth.can('cash.manage') || auth.can('finance.view')" />

    <!-- Où est l'argent en ce moment -->
    <section v-if="auth.can('finance.view')" class="space-y-3">
      <h2 class="text-lg font-semibold">Où est mon argent</h2>
      <div v-if="data" class="grid grid-cols-2 gap-3">
        <RouterLink to="/gestion/caisse/livreurs" class="card block p-4">
          <p class="text-sm text-slate-600">Chez mes livreurs (espèces)</p>
          <p class="whitespace-nowrap text-xl font-semibold sm:text-2xl">{{ money(data.position.couriers_cash) }}</p>
          <p class="text-xs text-slate-500">{{ data.position.couriers_cash_count }} livreur(s) à faire verser</p>
        </RouterLink>
        <component :is="auth.can('carriers.manage') ? 'RouterLink' : 'div'" to="/gestion/caisse/a-recevoir" class="card block p-4">
          <p class="text-sm text-slate-600">Chez les entreprises de livraison</p>
          <p class="whitespace-nowrap text-xl font-semibold sm:text-2xl" :class="data.position.carriers_due < 0 ? 'text-red-700' : ''">{{ money(data.position.carriers_due) }}</p>
          <p v-if="data.position.carriers_due_overdue" class="text-xs text-red-700">dont {{ money(data.position.carriers_due_overdue) }} en retard</p>
          <p v-else class="text-xs text-slate-500">Livré, pas encore reversé</p>
        </component>
        <RouterLink to="/gestion/caisse/clients" class="card block p-4">
          <p class="text-sm text-slate-600">Clients : reste à payer</p>
          <p class="whitespace-nowrap text-xl font-semibold sm:text-2xl">{{ money(data.position.customers_due) }}</p>
          <p class="text-xs text-slate-500">{{ data.position.customers_due_count }} commande(s) livrée(s) pas soldée(s)</p>
        </RouterLink>
        <component :is="auth.can('couriers.manage') ? 'RouterLink' : 'div'" to="/gestion/livraisons/livreurs" class="card block p-4">
          <p class="text-sm text-slate-600">À payer à mes livreurs</p>
          <p class="whitespace-nowrap text-xl font-semibold sm:text-2xl">{{ money(data.position.couriers_to_pay) }}</p>
          <p class="text-xs text-slate-500">Gains et relevés pas encore payés</p>
        </component>
      </div>
    </section>

    <!-- Entrées et sorties de la période -->
    <section v-if="auth.can('finance.view')" class="space-y-3">
      <h2 class="text-lg font-semibold">Entrées et sorties</h2>
      <CashFlowTable v-model:period="period" :flows="data?.flows" />
    </section>
  </div>
</template>
