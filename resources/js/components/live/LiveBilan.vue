<script setup>
import { computed, onMounted, ref } from 'vue'
import http from '../../lib/http'
import { MOMENT_NAMES } from '../../lib/liveShow'

/**
 * Bilan du show, après le live : quels moments ont fait vendre (commandes reçues dans les 2 minutes qui suivent
 * chaque moment), ce qu'il faut refaire, les articles à revoir et les quartiers qui ont le plus commandé.
 */
const props = defineProps({ id: { type: [Number, String], required: true } })
const bilan = ref(null)
onMounted(async () => {
  try {
    bilan.value = (await http.get(`/lives/${props.id}/bilan`)).data.data
  } catch {
    bilan.value = null
  }
})

const WINDOW = 2 * 60000
const rows = computed(() => {
  if (!bilan.value) return []
  const orders = bilan.value.orders.map((o) => new Date(o).getTime())
  const byType = {}
  for (const m of bilan.value.moments) {
    if (['defi_stop', 'hint', 'announce'].includes(m.type)) continue
    const at = new Date(m.at).getTime()
    const row = (byType[m.type] ??= { type: m.type, times: 0, orders: 0 })
    row.times += 1
    row.orders += orders.filter((o) => o > at && o <= at + WINDOW).length
  }
  return Object.values(byType).sort((a, b) => b.orders - a.orders || b.times - a.times)
})
const max = computed(() => Math.max(1, ...rows.value.map((r) => r.orders)))
const best = computed(() => rows.value.find((r) => r.orders > 0) ?? null)
const unsold = computed(() => (bilan.value?.items ?? []).filter((i) => i.sold === 0))
</script>

<template>
  <section v-if="bilan && (rows.length || unsold.length)" class="card space-y-3 p-4">
    <h3 class="font-extrabold">🎬 Ce qui a fait vendre</h3>
    <ul v-if="rows.length" class="space-y-2 text-sm">
      <li v-for="r in rows" :key="r.type" class="grid grid-cols-[1fr_auto] items-center gap-x-3">
        <span class="truncate font-semibold">{{ MOMENT_NAMES[r.type] ?? r.type }} <span class="font-normal text-slate-500">× {{ r.times }}</span></span>
        <b>{{ r.orders }} cmd</b>
        <span class="col-span-2 h-2 overflow-hidden rounded-full bg-slate-100"><i class="block h-full rounded-full bg-gradient-to-r from-rose-500 to-amber-400" :style="{ width: `${(r.orders / max) * 100}%` }" /></span>
      </li>
    </ul>
    <p v-else class="text-sm text-slate-500">Aucun moment lancé pendant ce live : la prochaine fois, essaie l'article mystère, le défi ou les 3 premiers.</p>
    <p class="text-xs text-slate-500">Commandes reçues dans les 2 minutes qui suivent chaque moment.</p>
    <p v-if="best" class="rounded-xl bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-800">👍 À refaire : {{ MOMENT_NAMES[best.type] ?? best.type }} ({{ best.orders }} commande{{ best.orders > 1 ? 's' : '' }} juste après)</p>
    <p v-if="unsold.length" class="rounded-xl bg-amber-50 px-3 py-2 text-sm font-bold text-amber-800">👀 À revoir : {{ unsold.map((i) => i.code).join(', ') }} {{ unsold.length > 1 ? 'n\'ont' : 'n\'a' }} rien vendu (photo, prix, moment où vous l'avez montré ?)</p>
    <p v-if="bilan.quarters.length" class="text-sm text-slate-600">📍 Quartiers qui ont le plus commandé : <b>{{ bilan.quarters.slice(0, 3).map((q) => `${q.name} (${q.orders})`).join(', ') }}</b></p>
  </section>
</template>
