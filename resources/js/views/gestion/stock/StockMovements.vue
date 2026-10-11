<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import Pagination from '../../../components/Pagination.vue'
import StockTabs from '../../../components/StockTabs.vue'
import http from '../../../lib/http'
import { formatDateTime } from '../../../lib/format'

const TYPES = {
  receipt: 'Réception', adjustment: 'Ajustement', count: 'Inventaire', damaged: 'Abîmé / perdu', reservation: 'Réservation',
  release: 'Libération', shipment: 'Expédition', return: 'Retour', transfer_out: 'Transfert (sortie)', transfer_in: 'Transfert (entrée)', import: 'Import',
}

const filters = reactive({ location_id: '', type: '', page: 1 })
const locations = ref([])
const movements = ref([])
const meta = ref(null)

async function load() {
  const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''))
  const { data } = await http.get('/stock/movements', { params })
  movements.value = data.data
  meta.value = data.meta
}

onMounted(async () => {
  locations.value = (await http.get('/stock/locations')).data.data
  await load()
})
watch(() => [filters.location_id, filters.type], () => { filters.page = 1; load() })

const sign = (n) => (n > 0 ? `+${n}` : `${n}`)
</script>

<template>
  <div class="space-y-4">
    <StockTabs />
    <div class="flex flex-wrap gap-2">
      <select v-model="filters.type" class="input w-auto">
        <option value="">Tous les mouvements</option>
        <option v-for="(label, value) in TYPES" :key="value" :value="value">{{ label }}</option>
      </select>
      <select v-if="locations.length > 1" v-model="filters.location_id" class="input w-auto">
        <option value="">Tous les emplacements</option>
        <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option>
      </select>
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="border-b border-slate-100 text-left text-xs uppercase text-slate-500">
          <tr>
            <th class="px-4 py-2 font-medium">Date</th>
            <th class="px-3 py-2 font-medium">Article</th>
            <th class="px-3 py-2 font-medium">Mouvement</th>
            <th class="px-3 py-2 text-right font-medium">Quantité</th>
            <th class="px-3 py-2 text-right font-medium">Après</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="m in movements" :key="m.id">
            <td class="whitespace-nowrap px-4 py-2 text-slate-500">{{ formatDateTime(m.created_at) }}</td>
            <td class="px-3 py-2">
              {{ m.variant }}
              <span class="block text-xs text-slate-400">{{ m.location }}{{ m.user ? ` · ${m.user}` : '' }}{{ m.note ? ` · ${m.note}` : '' }}</span>
            </td>
            <td class="px-3 py-2">{{ m.type_label }}</td>
            <td class="px-3 py-2 text-right font-medium" :class="m.on_hand_change < 0 ? 'text-red-600' : m.on_hand_change > 0 ? 'text-emerald-700' : 'text-slate-500'">
              {{ m.on_hand_change ? sign(m.on_hand_change) : `réservé ${sign(m.reserved_change)}` }}
            </td>
            <td class="px-3 py-2 text-right text-slate-500">{{ m.on_hand_after }}</td>
          </tr>
          <tr v-if="!movements.length"><td colspan="5" class="px-4 py-6 text-center text-slate-500">Aucun mouvement.</td></tr>
        </tbody>
      </table>
    </div>
    <Pagination :meta="meta" @page="(p) => { filters.page = p; load() }" />
  </div>
</template>
