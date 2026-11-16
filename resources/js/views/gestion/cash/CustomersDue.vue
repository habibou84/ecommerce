<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import CashTabs from '../../../components/CashTabs.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDate, money } from '../../../lib/format'

// Caisse › Clients : reste à payer — les commandes livrées pas encore soldées
const position = ref(null)
const error = ref('')

onMounted(async () => {
  try {
    position.value = (await http.get('/cash-overview', { params: { period: 'today' } })).data.data.position
  } catch (e) {
    error.value = errorMessage(e)
  }
})
</script>

<template>
  <div class="space-y-4">
    <CashTabs />
    <Alert v-if="error">{{ error }}</Alert>
    <p v-if="!position && !error" class="p-4 text-center text-sm text-slate-600">Chargement…</p>
    <template v-else-if="position">
      <div class="card p-4">
        <p class="text-sm text-slate-600">Reste à payer par les clients</p>
        <p class="whitespace-nowrap text-2xl font-semibold">{{ money(position.customers_due) }}</p>
        <p class="text-xs text-slate-600">{{ position.customers_due_count }} commande{{ position.customers_due_count > 1 ? 's livrées, pas soldées' : ' livrée, pas soldée' }}</p>
      </div>
      <p v-if="!position.customers_due_orders.length" class="p-4 text-center text-sm text-slate-600">Tous vos clients ont payé.</p>
      <ul v-else class="card divide-y divide-slate-100 text-sm">
        <li v-for="o in position.customers_due_orders" :key="o.id">
          <RouterLink :to="`/gestion/commandes/${o.id}`" class="flex items-center gap-2 px-4 py-3">
            <span class="min-w-0 flex-1">
              <b class="block truncate">{{ o.customer }}</b>
              <span class="text-xs text-slate-600">{{ o.reference }} · livrée le {{ formatDate(o.completed_at) }}</span>
            </span>
            <span class="whitespace-nowrap font-semibold">{{ money(o.due) }}</span>
            <span class="text-lg text-slate-400" aria-hidden="true">›</span>
          </RouterLink>
        </li>
      </ul>
    </template>
  </div>
</template>
