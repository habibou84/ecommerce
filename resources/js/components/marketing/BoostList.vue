<script setup>
import { onMounted, ref } from 'vue'
import http, { errorMessage } from '../../lib/http'
import { money } from '../../lib/format'

/**
 * Mes boosts : chaque pub créée par l'assistant avec son état (en vérification, en cours, en pause, refusée,
 * terminée), la dépense, les clics et les commandes venues de la pub ; pause, reprise, prolongation, arrêt, relance.
 */
const emit = defineEmits(['relaunch'])
const boosts = ref(null)
const error = ref('')
const busy = ref(0)
const confirmStop = ref(0)

async function load() {
  try {
    boosts.value = (await http.get('/marketing/boosts')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)
defineExpose({ load, add: (b) => (boosts.value = [b, ...(boosts.value ?? [])]) })

async function act(b, action, extra = {}) {
  busy.value = b.id
  error.value = ''
  try {
    const next = (await http.post(`/marketing/boosts/${b.id}/action`, { action, ...extra })).data.data
    boosts.value = boosts.value.map((x) => (x.id === b.id ? next : x))
    confirmStop.value = 0
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = 0
  }
}

const STATUS = {
  review: ['⏳ En vérification', 'bg-amber-50 text-amber-800'],
  active: ['● En cours', 'bg-emerald-50 text-emerald-700'],
  paused: ['⏸ En pause', 'bg-slate-100 text-slate-600'],
  refused: ['✕ Refusé par Meta', 'bg-red-50 text-red-700'],
  ended: ['Terminé', 'bg-slate-100 text-slate-600'],
  failed: ['✕ Pas créé', 'bg-red-50 text-red-700'],
}
const ICON = { product: '🎯', products: '🎠', catalog: '🗂️' }
</script>

<template>
  <div class="space-y-2">
    <p v-if="error" class="rounded-xl bg-red-50 p-2.5 text-sm font-bold text-red-700">{{ error }}</p>
    <p v-if="boosts && !boosts.length" class="text-sm text-slate-500">Aucun boost pour l'instant.</p>
    <article v-for="b in boosts ?? []" :key="b.id" class="space-y-2 rounded-xl border border-slate-200 p-3">
      <div class="flex items-start gap-2">
        <b class="min-w-0 flex-1 text-sm">{{ ICON[b.kind] }} {{ b.name }}</b>
        <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-extrabold" :class="STATUS[b.status][1]">{{ STATUS[b.status][0] }}{{ b.status === 'active' ? ` · jour ${b.day} sur ${b.days}` : '' }}</span>
      </div>
      <p class="text-xs text-slate-500">{{ money(b.daily_budget) }} / jour · {{ b.days }} jours</p>
      <p v-if="b.error && ['refused', 'failed'].includes(b.status)" class="rounded-lg bg-red-50 p-2 text-xs text-red-800">{{ b.error }}</p>

      <template v-if="!['failed', 'review'].includes(b.status) || b.spend">
        <div class="h-2 overflow-hidden rounded-full bg-slate-100" :aria-label="`Dépensé : ${money(b.spend)} sur ${money(b.budget_total)}`"><i class="block h-full rounded-full bg-[#1877f2]" :style="{ width: `${Math.min(100, (b.spend / Math.max(1, b.budget_total)) * 100)}%` }" /></div>
        <div class="grid grid-cols-3 gap-1 text-center">
          <div><b class="block font-display text-base tabular-nums">{{ money(b.spend) }}</b><span class="text-xs text-slate-500">dépensé</span></div>
          <div><b class="block font-display text-base tabular-nums">{{ b.clicks }}</b><span class="text-xs text-slate-500">clics</span></div>
          <div><b class="block font-display text-base tabular-nums">{{ b.orders }}</b><span class="text-xs text-slate-500">commande{{ b.orders > 1 ? 's' : '' }}</span></div>
        </div>
        <p v-if="b.cost_per_order" class="text-xs text-slate-500">{{ money(b.cost_per_order) }} de pub par commande · {{ money(b.sales) }} de ventes</p>
      </template>

      <div v-if="['active', 'paused', 'review'].includes(b.status)" class="flex gap-1.5">
        <button v-if="b.status === 'paused'" type="button" class="flex-1 rounded-lg bg-slate-100 py-2 text-sm font-bold" :disabled="busy === b.id" @click="act(b, 'resume')">▶ Reprendre</button>
        <button v-else type="button" class="flex-1 rounded-lg bg-slate-100 py-2 text-sm font-bold" :disabled="busy === b.id" @click="act(b, 'pause')">⏸ Pause</button>
        <button type="button" class="flex-1 rounded-lg bg-slate-100 py-2 text-sm font-bold" :disabled="busy === b.id" @click="act(b, 'extend', { days: 3 })">➕ 3 jours</button>
        <button v-if="confirmStop !== b.id" type="button" class="flex-1 rounded-lg bg-slate-100 py-2 text-sm font-bold" @click="confirmStop = b.id">⏹ Arrêter</button>
        <button v-else type="button" class="flex-1 rounded-lg bg-red-600 py-2 text-sm font-bold text-white" :disabled="busy === b.id" @click="act(b, 'stop')">Confirmer l'arrêt</button>
      </div>
      <button v-else type="button" class="w-full rounded-lg bg-[#e7f0fe] py-2 text-sm font-bold text-[#1877f2]" @click="emit('relaunch', b)">🔁 Relancer le même boost</button>
    </article>
  </div>
</template>
