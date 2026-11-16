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
    <div class="grid grid-cols-4 gap-0.5 rounded-lg bg-slate-200 p-1 text-center text-sm" role="group" aria-label="Période">
      <button
        v-for="p in PERIODS"
        :key="p.value"
        type="button"
        class="rounded-md px-1 py-2"
        :class="period === p.value ? 'bg-white font-semibold shadow-sm' : 'text-slate-700'"
        :aria-pressed="period === p.value"
        @click="period = p.value"
      >
        {{ p.label }}
      </button>
    </div>
    <p v-if="!flows" class="p-4 text-center text-sm text-slate-600">Chargement…</p>
    <div v-else class="card overflow-hidden text-sm">
      <ul class="divide-y divide-slate-100">
        <li v-for="l in flows.lines" :key="l.key" class="flex items-center gap-2 px-4 py-3" :class="{ 'text-slate-500': !l.count }">
          <span class="min-w-0 flex-1">
            <span class="block">{{ l.label }} <span v-if="l.count" class="text-xs text-slate-600">({{ l.count }})</span></span>
            <span v-if="l.cash" class="block text-xs text-slate-600">dont espèces : {{ signed(l.cash) }}</span>
          </span>
          <span class="whitespace-nowrap font-semibold" :class="l.amount < 0 ? 'text-red-700' : ''">{{ l.amount ? signed(l.amount) : '—' }}</span>
        </li>
      </ul>
      <div class="flex items-center gap-2 bg-emerald-50 px-4 py-3.5">
        <span class="min-w-0 flex-1">
          <span class="block font-bold">Solde de la période</span>
          <span class="block text-xs text-emerald-900">dont espèces : {{ signed(flows.cash) }}</span>
        </span>
        <span class="whitespace-nowrap text-lg font-bold" :class="flows.total < 0 ? 'text-red-700' : 'text-emerald-800'">{{ signed(flows.total) }}</span>
      </div>
    </div>
    <p class="explain">Une somme compte le jour où vous la recevez.</p>
    <p class="explain-detail">
      « Espèces » : l'argent à mettre dans le tiroir.
      <template v-if="flows?.online_fees"> Frais des paiements en ligne : {{ money(flows.online_fees) }}.</template>
    </p>
  </div>
</template>
