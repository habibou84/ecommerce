<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import Pagination from '../../../components/Pagination.vue'
import http from '../../../lib/http'
import { formatDate, money } from '../../../lib/format'

const filters = reactive({ search: '', filter: '', sort: 'recent', page: 1 })
const customers = ref([])
const meta = ref(null)

async function load() {
  const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''))
  const { data } = await http.get('/customers', { params })
  customers.value = data.data
  meta.value = data.meta
}
onMounted(load)

let timer
watch(() => filters.search, () => {
  clearTimeout(timer)
  timer = setTimeout(() => { filters.page = 1; load() }, 300)
})
watch(() => [filters.filter, filters.sort], () => { filters.page = 1; load() })
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-col gap-2 sm:flex-row">
      <input v-model="filters.search" type="search" class="input sm:max-w-xs" placeholder="Nom ou téléphone" />
      <select v-model="filters.filter" class="input w-auto">
        <option value="">Tous les clients</option>
        <option value="loyal">Fidèles (3 livraisons et plus)</option>
        <option value="risky">Avec refus</option>
        <option value="blacklisted">Liste noire</option>
      </select>
      <select v-model="filters.sort" class="input w-auto">
        <option value="recent">Dernière commande</option>
        <option value="spent">Montant dépensé</option>
        <option value="orders">Nombre de commandes</option>
      </select>
    </div>

    <div class="card">
      <ul class="divide-y divide-slate-100">
        <li v-for="c in customers" :key="c.id">
          <RouterLink :to="`/gestion/clients/${c.id}`" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 hover:bg-slate-50">
            <span class="min-w-0 flex-1">
              <span class="block font-medium">
                {{ c.name || 'Sans nom' }}
                <span v-if="c.is_blacklisted" class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700">Liste noire</span>
                <span v-for="t in c.tags" :key="t" class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs">{{ t }}</span>
              </span>
              <span class="block text-sm text-slate-500">{{ c.phone_display }}<template v-if="c.last_order_at"> · dernière commande le {{ formatDate(c.last_order_at) }}</template></span>
            </span>
            <span class="text-right text-sm">
              <span class="block font-medium">{{ money(c.total_spent) }}</span>
              <span class="block text-slate-500">{{ c.orders_count }} cmd<template v-if="c.delivery_rate !== null"> · {{ c.delivery_rate }} % livrées</template></span>
            </span>
          </RouterLink>
        </li>
        <li v-if="!customers.length" class="px-4 py-8 text-center text-sm text-slate-500">Aucun client.</li>
      </ul>
    </div>
    <Pagination :meta="meta" @page="(p) => { filters.page = p; load() }" />
  </div>
</template>
