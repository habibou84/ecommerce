<script setup>
import { onMounted, ref } from 'vue'
import http from '../../lib/http'
import { formatDateTime, money } from '../../lib/format'

const cash = ref(null)
const earnings = ref(null)

onMounted(async () => {
  const [c, e] = await Promise.all([http.get('/courier/cash'), http.get('/courier/earnings')])
  cash.value = c.data.data
  earnings.value = e.data.data
})
</script>

<template>
  <div v-if="cash && earnings" class="space-y-4">
    <section class="rounded-xl bg-white p-4 shadow-sm">
      <p class="text-sm text-slate-500">Espèces à verser à la boutique</p>
      <p class="text-2xl font-bold">{{ money(cash.held) }}</p>
      <ul class="mt-2 divide-y divide-slate-100 text-sm">
        <li v-for="(c, i) in cash.collections" :key="i" class="flex justify-between py-1.5"><span>{{ c.reference }} · {{ formatDateTime(c.collected_at) }}</span><span>{{ money(c.amount) }}</span></li>
      </ul>
    </section>
    <section class="rounded-xl bg-white p-4 shadow-sm">
      <p class="text-sm text-slate-500">Ma paie à recevoir</p>
      <p class="text-2xl font-bold text-emerald-700">{{ money(earnings.unpaid) }}</p>
      <ul class="mt-2 divide-y divide-slate-100 text-sm">
        <li v-for="(l, i) in earnings.lines" :key="i" class="flex justify-between py-1.5">
          <span>{{ l.type_label }}<template v-if="l.reference"> · {{ l.reference }}</template></span>
          <span :class="{ 'text-red-600': l.amount < 0 }">{{ money(l.amount) }}</span>
        </li>
      </ul>
    </section>
    <section v-if="cash.remittances.length" class="rounded-xl bg-white p-4 shadow-sm">
      <p class="mb-1 text-sm font-semibold">Mes derniers versements</p>
      <ul class="divide-y divide-slate-100 text-sm">
        <li v-for="(r, i) in cash.remittances" :key="i" class="flex justify-between py-1.5">
          <span>{{ formatDateTime(r.received_at) }}</span>
          <span>{{ money(r.received_amount) }}<span v-if="r.difference" class="ml-1 text-amber-700">({{ r.difference > 0 ? '+' : '' }}{{ money(r.difference) }})</span></span>
        </li>
      </ul>
    </section>
  </div>
</template>
