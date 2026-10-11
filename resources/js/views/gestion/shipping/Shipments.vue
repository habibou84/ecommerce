<script setup>
import { onMounted, ref, watch } from 'vue'
import Alert from '../../../components/Alert.vue'
import Pagination from '../../../components/Pagination.vue'
import ShippingTabs from '../../../components/ShippingTabs.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'
import { SHIPMENT_TONES } from '../../../lib/shipping'

const filter = ref('active')
const page = ref(1)
const result = ref(null)
const error = ref('')

async function load() {
  try {
    result.value = (await http.get('/shipments', { params: { status: filter.value === 'all' ? null : filter.value, page: page.value } })).data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)
watch(filter, () => {
  page.value = 1
  load()
})
watch(page, load)
</script>

<template>
  <div class="space-y-4">
    <ShippingTabs />
    <div class="flex gap-2">
      <button v-for="f in [['active', 'En cours'], ['problems', 'À traiter'], ['all', 'Toutes']]" :key="f[0]" class="rounded-full px-3 py-1.5 text-sm"
              :class="filter === f[0] ? 'bg-emerald-700 text-white' : 'bg-slate-100 text-slate-700'" @click="filter = f[0]">{{ f[1] }}</button>
    </div>
    <Alert v-if="error">{{ error }}</Alert>

    <div class="card">
      <p v-if="result && !result.data.length" class="p-6 text-sm text-slate-500">Aucune expédition. Ouvrez une commande confirmée et cliquez sur « Expédier ».</p>
      <ul v-else class="divide-y divide-slate-100">
        <li v-for="s in result?.data ?? []" :key="s.id">
          <RouterLink :to="`/gestion/commandes/${s.order_id}`" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-sm hover:bg-slate-50">
            <span class="w-24 font-medium">{{ s.reference }}</span>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="SHIPMENT_TONES[s.status]">{{ s.status_label }}</span>
            <span class="min-w-0 flex-1 truncate">{{ s.customer_name }} · {{ s.place }}</span>
            <span class="text-slate-500">{{ s.carrier?.name }}<template v-if="s.tracking_code"> · {{ s.tracking_code }}</template></span>
            <span class="w-24 text-right">{{ money(s.cod_amount) }}</span>
            <span class="w-32 text-right text-xs text-slate-500">{{ formatDateTime(s.requested_at) }}</span>
          </RouterLink>
        </li>
      </ul>
    </div>
    <Pagination :meta="result?.meta" @page="page = $event" />
  </div>
</template>
