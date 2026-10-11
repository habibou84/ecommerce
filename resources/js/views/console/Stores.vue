<script setup>
import { onMounted, reactive, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'
import { formatDateTime } from '../../lib/format'

const filters = reactive({ search: '', status: '', plan: '', page: 1 })
const result = ref(null)
const error = ref('')
const TONES = { trial: 'bg-sky-100 text-sky-800', active: 'bg-emerald-100 text-emerald-800', past_due: 'bg-amber-100 text-amber-900', suspended: 'bg-red-100 text-red-800', closed: 'bg-slate-100 text-slate-600' }
let timer

async function load() {
  try {
    result.value = (await http.get('/console/stores', { params: { search: filters.search || undefined, status: filters.status || undefined, plan: filters.plan || undefined, page: filters.page } })).data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)
const debounced = () => { clearTimeout(timer); filters.page = 1; timer = setTimeout(load, 300) }
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap gap-2">
      <input v-model="filters.search" class="input max-w-xs" placeholder="Nom, adresse ou téléphone du propriétaire" @input="debounced" />
      <select v-model="filters.status" class="input w-auto" @change="debounced">
        <option value="">Tous les statuts</option><option value="trial">Essai</option><option value="active">Active</option><option value="past_due">En retard</option><option value="suspended">Suspendue</option><option value="closed">Fermée</option>
      </select>
      <select v-model="filters.plan" class="input w-auto" @change="debounced">
        <option value="">Toutes les offres</option><option value="free">Gratuit</option><option value="starter">Starter</option><option value="pro">Pro</option><option value="business">Business</option>
      </select>
    </div>
    <Alert v-if="error">{{ error }}</Alert>
    <div v-if="result" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="border-b border-slate-200 text-left text-xs text-slate-500"><tr><th class="px-4 py-2">Boutique</th><th>Propriétaire</th><th>Offre</th><th>Statut</th><th class="text-right">Commandes 30 j</th><th class="px-4 text-right">Créée le</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="s in result.data" :key="s.id" class="hover:bg-slate-50">
            <td class="px-4 py-2"><RouterLink :to="`/console/boutiques/${s.id}`" class="font-medium text-emerald-800 hover:underline">{{ s.name }}</RouterLink><span class="block text-xs text-slate-500">{{ s.slug }}</span></td>
            <td>{{ s.owner_name }}<span class="block text-xs text-slate-500">{{ s.owner_phone }}</span></td>
            <td>{{ s.plan }}<span v-if="s.subscription_status === 'trialing'" class="text-xs text-slate-500"> (essai)</span></td>
            <td><span class="rounded px-2 py-0.5 text-xs" :class="TONES[s.status]">{{ s.status_label }}</span></td>
            <td class="text-right">{{ s.orders_30d }}</td>
            <td class="px-4 text-right text-slate-500">{{ formatDateTime(s.created_at) }}</td>
          </tr>
          <tr v-if="!result.data.length"><td colspan="6" class="px-4 py-6 text-slate-500">Aucune boutique.</td></tr>
        </tbody>
      </table>
      <div v-if="result.meta.last_page > 1" class="flex items-center gap-2 border-t border-slate-100 px-4 py-2 text-sm">
        <button class="btn-ghost px-2" :disabled="filters.page <= 1" @click="filters.page--; load()">←</button>
        <span>Page {{ result.meta.current_page }} / {{ result.meta.last_page }} · {{ result.meta.total }} boutiques</span>
        <button class="btn-ghost px-2" :disabled="filters.page >= result.meta.last_page" @click="filters.page++; load()">→</button>
      </div>
    </div>
  </div>
</template>
