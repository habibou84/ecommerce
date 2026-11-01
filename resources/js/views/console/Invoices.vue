<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'
import { formatDateTime, money } from '../../lib/format'

const result = ref(null)
const status = ref('')
const error = ref('')
const page = ref(1)

async function load() {
  try {
    result.value = (await http.get('/console/invoices', { params: { status: status.value || undefined, page: page.value } })).data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)
</script>

<template>
  <div class="space-y-4">
    <select v-model="status" class="input w-auto" @change="page = 1; load()">
      <option value="">Toutes les factures</option><option value="paid">Payées</option><option value="offered">Offertes</option><option value="pending">En attente</option><option value="failed">Échouées</option>
    </select>
    <Alert v-if="error">{{ error }}</Alert>
    <div v-if="result" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="border-b border-slate-200 text-left text-xs text-slate-500"><tr><th class="px-4 py-2">N°</th><th>Espace marchand</th><th>Offre</th><th>Statut</th><th>Moyen</th><th class="text-right">Montant</th><th class="px-4 text-right">Date</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="i in result.data" :key="i.id">
            <td class="px-4 py-2 font-mono text-xs">{{ i.number }}</td>
            <td><RouterLink :to="`/console/boutiques/${i.store.id}`" class="text-emerald-800 hover:underline">{{ i.store.name }}</RouterLink></td>
            <td>{{ i.plan }} ({{ i.cycle_label }})</td>
            <td>{{ i.status_label }}<span v-if="i.error" class="block max-w-xs text-xs text-red-700">{{ i.error }}</span></td>
            <td>{{ i.method ?? '—' }}</td>
            <td class="text-right font-medium">{{ money(i.amount) }}</td>
            <td class="px-4 text-right text-slate-500">{{ formatDateTime(i.paid_at ?? i.created_at) }}</td>
          </tr>
          <tr v-if="!result.data.length"><td colspan="7" class="px-4 py-6 text-slate-500">Aucune facture.</td></tr>
        </tbody>
      </table>
      <div v-if="result.meta.last_page > 1" class="flex items-center gap-2 border-t border-slate-100 px-4 py-2 text-sm">
        <button class="btn-ghost px-2" :disabled="page <= 1" @click="page--; load()">←</button>
        <span>Page {{ result.meta.current_page }} / {{ result.meta.last_page }}</span>
        <button class="btn-ghost px-2" :disabled="page >= result.meta.last_page" @click="page++; load()">→</button>
      </div>
    </div>
  </div>
</template>
