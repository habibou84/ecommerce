<script setup>
import { computed, onMounted, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'
import { formatDateTime } from '../../lib/format'

// WhatsApp des boutiques : état de chaque numéro officiel chez Meta, les boutiques à aider d'abord
const result = ref(null)
const error = ref('')
const filter = ref('help')
const BADGE = { blocked: ['Bloqué', 'bg-red-100 text-red-800'], partial: ['En partie', 'bg-amber-100 text-amber-900'], ok: ['OK', 'bg-emerald-100 text-emerald-800'], unknown: ['Pas encore vérifié', 'bg-slate-100 text-slate-600'] }

async function load() {
  try {
    result.value = (await http.get('/console/whatsapp-health', { params: { filter: filter.value } })).data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

// « 2 jours », « 5 h »
function since(iso) {
  if (!iso) return ''
  const hours = Math.max(0, Math.round((Date.now() - new Date(iso)) / 3600000))
  return hours < 24 ? `${hours} h` : `${Math.round(hours / 24)} jour${hours >= 48 ? 's' : ''}`
}
const counts = computed(() => result.value?.counts ?? {})
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2">
      <h2 class="flex-1 text-lg font-bold">WhatsApp des boutiques</h2>
      <span v-if="counts.blocked" class="rounded-full bg-red-100 px-2.5 py-1 text-xs font-bold text-red-800">{{ counts.blocked }} bloquée{{ counts.blocked > 1 ? 's' : '' }}</span>
      <span v-if="counts.partial" class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-900">{{ counts.partial }} en partie</span>
    </div>
    <div class="flex flex-wrap gap-1.5 text-sm" role="radiogroup" aria-label="Filtrer">
      <button v-for="[key, text] in [['help', `À aider (${(counts.blocked ?? 0) + (counts.partial ?? 0)})`], ['all', `Toutes (${counts.all ?? 0})`]]" :key="key" type="button" role="radio" :aria-checked="filter === key"
              class="rounded-full px-3 py-1 font-medium" :class="filter === key ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'" @click="filter = key; load()">{{ text }}</button>
      <span class="self-center text-xs text-slate-500">· {{ counts.without ?? 0 }} boutique(s) sans numéro officiel</span>
    </div>
    <Alert v-if="error">{{ error }}</Alert>
    <div v-if="result" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="border-b border-slate-200 text-left text-xs text-slate-500"><tr><th class="px-4 py-2">Boutique</th><th>Numéro</th><th>État</th><th>Raison</th><th>Depuis</th><th class="px-4 text-right">Vérifié</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="(a, i) in result.data" :key="i" class="hover:bg-slate-50">
            <td class="px-4 py-2"><RouterLink v-if="a.store" :to="`/console/boutiques/${a.store.id}`" class="font-medium text-emerald-800 hover:underline">{{ a.store.name }}</RouterLink></td>
            <td class="whitespace-nowrap">{{ a.phone }}</td>
            <td><span class="rounded px-2 py-0.5 text-xs font-semibold" :class="(BADGE[a.state] ?? BADGE.unknown)[1]">{{ (BADGE[a.state] ?? BADGE.unknown)[0] }}</span></td>
            <td>{{ a.reason ?? '—' }}</td>
            <td class="whitespace-nowrap">{{ since(a.since) }}</td>
            <td class="whitespace-nowrap px-4 text-right text-slate-500">{{ a.checked_at ? formatDateTime(a.checked_at) : '—' }}</td>
          </tr>
          <tr v-if="!result.data.length"><td colspan="6" class="px-4 py-6 text-slate-500">{{ filter === 'help' ? 'Aucune boutique bloquée par Meta.' : 'Aucun numéro officiel connecté.' }}</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
