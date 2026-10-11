<script setup>
import { computed, onMounted, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'
import { money } from '../../lib/format'

const stats = ref(null)
const error = ref('')
const STATUS = { trial: 'Essai', active: 'Actives', past_due: 'En retard', suspended: 'Suspendues', closed: 'Fermées' }

onMounted(async () => {
  try {
    stats.value = (await http.get('/console/stats')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
})

// Inscriptions des 30 derniers jours (barres)
const days = computed(() => {
  if (!stats.value) return []
  const out = []
  for (let i = 29; i >= 0; i--) {
    const d = new Date(Date.now() - i * 86400000).toISOString().slice(0, 10)
    out.push({ day: d, total: Number(stats.value.signups[d] ?? 0) })
  }
  return out
})
const maxDay = computed(() => Math.max(1, ...days.value.map((d) => d.total)))
</script>

<template>
  <div class="space-y-5">
    <Alert v-if="error">{{ error }}</Alert>
    <template v-if="stats">
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4"><p class="text-sm text-slate-500">Boutiques</p><p class="text-2xl font-semibold">{{ stats.stores }}</p><p class="text-xs text-slate-500">+{{ stats.new_7d }} en 7 j · +{{ stats.new_30d }} en 30 j</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">Actives (30 j)</p><p class="text-2xl font-semibold">{{ stats.active_30d }}</p><p class="text-xs text-slate-500">au moins une commande</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">Revenu mensuel récurrent</p><p class="text-2xl font-semibold">{{ money(stats.mrr) }}</p><p class="text-xs text-slate-500">{{ money(stats.billed_30d) }} encaissés en 30 j</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">Ventes livrées (30 j)</p><p class="text-2xl font-semibold">{{ money(stats.gmv_30d) }}</p><p class="text-xs text-slate-500">{{ stats.orders_30d }} commandes</p></div>
      </div>
      <div class="grid gap-5 lg:grid-cols-[2fr_1fr]">
        <section class="card p-4">
          <h3 class="mb-3 font-semibold">Créations de boutiques (30 jours)</h3>
          <div class="flex h-32 items-end gap-1">
            <div v-for="d in days" :key="d.day" class="flex-1 rounded-t bg-emerald-600" :style="{ height: `${(d.total / maxDay) * 100}%`, minHeight: d.total ? '4px' : '1px' }" :title="`${d.day} : ${d.total}`" />
          </div>
        </section>
        <section class="card space-y-2 p-4 text-sm">
          <h3 class="font-semibold">Statuts</h3>
          <div v-for="(total, status) in stats.by_status" :key="status" class="flex justify-between"><span>{{ STATUS[status] ?? status }}</span><span class="font-medium">{{ total }}</span></div>
          <h3 class="pt-2 font-semibold">Offres</h3>
          <div v-for="row in stats.by_plan" :key="row.plan + row.status" class="flex justify-between"><span>{{ row.plan }} <span class="text-slate-500">({{ row.status === 'trialing' ? 'essai' : row.status === 'past_due' ? 'en retard' : 'payée' }})</span></span><span class="font-medium">{{ row.total }}</span></div>
          <p v-if="stats.trials_ending_7d" class="pt-2 text-amber-800">{{ stats.trials_ending_7d }} essai(s) se terminent dans 7 jours.</p>
        </section>
      </div>
    </template>
  </div>
</template>
