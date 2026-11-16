<script setup>
import { money } from '../lib/format'

// Entrées et sorties d'argent d'une période, canal par canal (données de GET cash-overview)
defineProps({ flows: { type: Object, default: null } })
const period = defineModel('period', { type: String, default: 'today' })

const PERIODS = [
  { value: 'today', label: "Aujourd'hui" },
  { value: 'week', label: '7 jours' },
  { value: 'month', label: 'Ce mois' },
  { value: '30d', label: '30 jours' },
]
const signed = (amount) => (amount > 0 ? '+' : '') + money(amount)
</script>

<template>
  <div class="space-y-3">
    <div class="flex rounded-lg bg-slate-100 p-1 text-sm" role="group" aria-label="Période">
      <button
        v-for="p in PERIODS"
        :key="p.value"
        type="button"
        class="flex-1 whitespace-nowrap rounded-md px-3 py-1"
        :class="period === p.value ? 'bg-white shadow-sm' : 'text-slate-600'"
        @click="period = p.value"
      >
        {{ p.label }}
      </button>
    </div>
    <p v-if="!flows" class="p-4 text-center text-sm text-slate-500">Chargement…</p>
    <div v-else class="card overflow-hidden text-sm">
      <table class="w-full">
        <thead class="bg-slate-50 text-left text-xs text-slate-500">
          <tr><th class="px-4 py-2">Canal</th><th class="px-2 py-2 text-right">Montant</th><th class="px-4 py-2 text-right">dont espèces</th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="l in flows.lines" :key="l.key" :class="{ 'text-slate-400': !l.count }">
            <td class="px-4 py-2">{{ l.label }} <span v-if="l.count" class="text-xs text-slate-500">({{ l.count }})</span></td>
            <td class="whitespace-nowrap px-2 py-2 text-right font-medium" :class="l.amount < 0 ? 'text-red-700' : ''">{{ l.amount ? signed(l.amount) : '—' }}</td>
            <td class="whitespace-nowrap px-4 py-2 text-right">{{ l.cash ? signed(l.cash) : '—' }}</td>
          </tr>
        </tbody>
        <tfoot class="border-t-2 border-slate-200 font-semibold">
          <tr>
            <td class="px-4 py-2">Solde de la période</td>
            <td class="whitespace-nowrap px-2 py-2 text-right" :class="flows.total < 0 ? 'text-red-700' : 'text-emerald-800'">{{ signed(flows.total) }}</td>
            <td class="whitespace-nowrap px-4 py-2 text-right">{{ signed(flows.cash) }}</td>
          </tr>
        </tfoot>
      </table>
    </div>
    <p class="text-sm text-slate-500">
      Chaque somme compte une fois, quand vous la recevez. « Espèces » : à mettre dans le tiroir.
      <template v-if="flows?.online_fees"> Frais des paiements en ligne : {{ money(flows.online_fees) }}.</template>
    </p>
  </div>
</template>
