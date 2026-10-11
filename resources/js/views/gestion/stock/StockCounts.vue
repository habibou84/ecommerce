<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import StatusBadge from '../../../components/StatusBadge.vue'
import StockTabs from '../../../components/StockTabs.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime } from '../../../lib/format'

const router = useRouter()
const counts = ref([])
const locations = ref([])
const locationId = ref(null)
const error = ref('')

onMounted(async () => {
  const [c, l] = await Promise.all([http.get('/stock/counts'), http.get('/stock/locations')])
  counts.value = c.data.data
  locations.value = l.data.data.filter((x) => x.is_active)
  locationId.value = locations.value[0]?.id
})

async function start() {
  error.value = ''
  try {
    const { data } = await http.post('/stock/counts', { location_id: locationId.value })
    router.push(`/gestion/stock/inventaires/${data.data.id}`)
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="space-y-4">
    <StockTabs />
    <div class="card space-y-3 p-4">
      <p class="text-sm text-slate-600">Comptez ce qui est réellement en rayon. À la validation, les quantités comptées remplacent le stock ; les articles non comptés ne changent pas.</p>
      <div class="flex flex-wrap gap-2">
        <select v-model="locationId" class="input w-auto">
          <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option>
        </select>
        <button class="btn-brand" :disabled="!locationId" @click="start">Commencer un inventaire</button>
      </div>
      <Alert v-if="error">{{ error }}</Alert>
    </div>

    <div class="card">
      <ul class="divide-y divide-slate-100">
        <li v-for="c in counts" :key="c.id">
          <RouterLink :to="`/gestion/stock/inventaires/${c.id}`" class="flex items-center gap-3 px-4 py-3 text-sm hover:bg-slate-50">
            <span class="min-w-0 flex-1">
              <span class="block font-medium">{{ c.location }}</span>
              <span class="block text-slate-500">{{ formatDateTime(c.created_at) }} · {{ c.counted_count }} / {{ c.items_count }} compté(s)</span>
            </span>
            <StatusBadge :status="c.status" :label="c.status_label" />
          </RouterLink>
        </li>
        <li v-if="!counts.length" class="px-4 py-6 text-center text-sm text-slate-500">Aucun inventaire.</li>
      </ul>
    </div>
  </div>
</template>
