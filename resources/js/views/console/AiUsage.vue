<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'
import { money } from '../../lib/format'

// Coût estimé de l'assistant IA : par boutique et par modèle, au prix public des modèles
const data = ref(null)
const month = ref('')
const error = ref('')

async function load() {
  try {
    data.value = (await http.get('/console/ai-usage', { params: { month: month.value || undefined } })).data.data
    month.value = data.value.month
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

const monthLabel = (m) => new Date(`${m}-01T12:00:00`).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
const tokens = (n) => (n >= 1_000_000 ? `${(n / 1_000_000).toFixed(1)} M` : n >= 1000 ? `${Math.round(n / 1000)} k` : String(n))
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
      <select v-model="month" class="input w-auto" aria-label="Mois" @change="load">
        <option v-for="m in data?.months ?? []" :key="m" :value="m">{{ monthLabel(m) }}</option>
      </select>
      <p class="text-sm text-slate-500">Coût estimé au prix public des modèles, 1 $ = {{ data?.usd_rate }} F. La facture Anthropic fait foi.</p>
    </div>
    <Alert v-if="error">{{ error }}</Alert>

    <template v-if="data">
      <div class="grid gap-3 sm:grid-cols-4">
        <div class="card p-4"><p class="text-xs text-slate-500">Coût estimé</p><p class="text-2xl font-semibold">{{ money(data.total.cost) }}</p><p class="text-xs text-slate-400">{{ data.total.cost_usd }} $</p></div>
        <div class="card p-4"><p class="text-xs text-slate-500">Actions réussies</p><p class="text-2xl font-semibold">{{ data.total.actions }}</p></div>
        <div class="card p-4"><p class="text-xs text-slate-500">Échecs (non comptés)</p><p class="text-2xl font-semibold">{{ data.total.failures }}</p></div>
        <div class="card p-4"><p class="text-xs text-slate-500">Espaces marchands</p><p class="text-2xl font-semibold">{{ data.total.stores }}</p></div>
      </div>

      <div v-if="data.models.length" class="card flex flex-wrap gap-x-6 gap-y-1 p-4 text-sm">
        <span v-for="m in data.models" :key="m.model">{{ m.model }} : {{ m.actions }} action(s), {{ money(m.cost) }}<span v-if="!m.priced" class="text-amber-700"> (prix inconnu : estimé au plus cher)</span></span>
      </div>

      <div class="card overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="border-b border-slate-200 text-left text-xs text-slate-500">
            <tr><th class="px-4 py-2">Espace marchand</th><th>Offre</th><th class="text-right">Actions</th><th class="text-right">Jetons (entrée / sortie)</th><th class="text-right">Coût estimé</th><th class="px-4 text-right">Part de l'abonnement</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="s in data.stores" :key="s.store.id">
              <td class="px-4 py-2"><RouterLink :to="`/console/boutiques/${s.store.id}`" class="text-emerald-800 hover:underline">{{ s.store.name }}</RouterLink></td>
              <td>{{ s.plan ?? '—' }}</td>
              <td class="text-right">{{ s.actions }}<span v-if="s.quota !== null && s.quota !== undefined" class="text-slate-400"> / {{ s.quota }}</span><span v-if="s.failures" class="text-amber-700"> ({{ s.failures }} échec{{ s.failures > 1 ? 's' : '' }})</span></td>
              <td class="text-right text-slate-500">{{ tokens(s.input_tokens) }} / {{ tokens(s.output_tokens) }}</td>
              <td class="text-right font-medium">{{ money(s.cost) }}</td>
              <td class="px-4 text-right" :class="s.plan_price && s.cost > s.plan_price * 0.3 ? 'font-medium text-red-600' : 'text-slate-500'">
                {{ s.plan_price ? `${Math.round((s.cost / s.plan_price) * 100)} %` : '—' }}
              </td>
            </tr>
            <tr v-if="!data.stores.length"><td colspan="6" class="px-4 py-6 text-slate-500">Aucune action de l'assistant ce mois-ci.</td></tr>
          </tbody>
        </table>
      </div>
      <p class="text-xs text-slate-500">En rouge : l'IA coûte plus de 30 % du prix mensuel de l'offre de l'espace marchand.</p>
    </template>
  </div>
</template>
