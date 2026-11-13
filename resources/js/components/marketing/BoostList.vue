<script setup>
import { computed, onMounted, ref } from 'vue'
import http, { errorMessage } from '../../lib/http'
import { money } from '../../lib/format'

/**
 * Mes boosts : chaque pub créée par l'assistant avec son état (en vérification, en cours, en pause, refusée,
 * terminée), la dépense, les clics et les commandes venues de la pub ; pause, reprise, prolongation, arrêt, relance,
 * suppression d'un boost « pas créé » (sans dépense ni commande).
 */
const emit = defineEmits(['relaunch'])
const boosts = ref(null)
const error = ref('')
const busy = ref(0)
const confirmStop = ref(0)
const confirmDelete = ref(0)

// Un boost « pas créé » sans dépense ni commande peut être supprimé sans rien perdre
const deletable = (b) => b.status === 'failed' && !b.spend && !b.orders
const failedCount = computed(() => (boosts.value ?? []).filter(deletable).length)

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

async function remove(b) {
  busy.value = b.id
  error.value = ''
  try {
    await http.delete(`/marketing/boosts/${b.id}`)
    boosts.value = boosts.value.filter((x) => x.id !== b.id)
    confirmDelete.value = 0
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = 0
  }
}

async function clearFailed() {
  error.value = ''
  try {
    await http.delete('/marketing/boosts/failed')
    boosts.value = boosts.value.filter((x) => !deletable(x))
  } catch (e) {
    error.value = errorMessage(e)
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
    <button v-if="failedCount > 1" type="button" class="w-full rounded-lg bg-red-50 py-2 text-sm font-bold text-red-700" @click="clearFailed">🗑 Effacer les {{ failedCount }} boosts pas créés</button>
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

      <!-- Conseil : la pub coûte-t-elle plus que la marge ? -->
      <div v-if="b.advice?.kind === 'go'" class="space-y-2 rounded-lg bg-emerald-50 p-2.5 text-sm">
        <p>✅ <b>Ça rapporte</b> : {{ money(b.cost_per_order) }} de pub par commande pour {{ money(b.advice.margin) }} de marge.</p>
        <button type="button" class="w-full rounded-lg bg-emerald-700 py-2 text-sm font-bold text-white" :disabled="busy === b.id" @click="act(b, 'extend', { days: 7 })">➕ Prolonger 7 jours</button>
      </div>
      <div v-else-if="b.advice?.kind === 'stop'" class="space-y-2 rounded-lg bg-red-50 p-2.5 text-sm">
        <p>🛑 <b>Pas rentable</b> : {{ b.orders ? `${money(b.cost_per_order)} de pub par commande` : `aucune commande pour ${money(b.spend)} de pub` }}{{ b.advice.margin ? `, votre marge est de ${money(b.advice.margin)}` : '' }}.</p>
        <button v-if="b.status === 'active'" type="button" class="w-full rounded-lg bg-red-600 py-2 text-sm font-bold text-white" :disabled="busy === b.id" @click="act(b, 'pause')">⏸ Mettre en pause</button>
      </div>
      <p v-else-if="b.advice?.kind === 'cost'" class="rounded-lg bg-slate-50 p-2.5 text-xs text-slate-600">💡 Ajoutez le prix d'achat de vos produits (fiche produit) pour savoir si ce boost est rentable.</p>

      <div v-if="['active', 'paused', 'review'].includes(b.status)" class="flex gap-1.5">
        <button v-if="b.status === 'paused'" type="button" class="flex-1 rounded-lg bg-slate-100 py-2 text-sm font-bold" :disabled="busy === b.id" @click="act(b, 'resume')">▶ Reprendre</button>
        <button v-else type="button" class="flex-1 rounded-lg bg-slate-100 py-2 text-sm font-bold" :disabled="busy === b.id" @click="act(b, 'pause')">⏸ Pause</button>
        <button type="button" class="flex-1 rounded-lg bg-slate-100 py-2 text-sm font-bold" :disabled="busy === b.id" @click="act(b, 'extend', { days: 3 })">➕ 3 jours</button>
        <button v-if="confirmStop !== b.id" type="button" class="flex-1 rounded-lg bg-slate-100 py-2 text-sm font-bold" @click="confirmStop = b.id">⏹ Arrêter</button>
        <button v-else type="button" class="flex-1 rounded-lg bg-red-600 py-2 text-sm font-bold text-white" :disabled="busy === b.id" @click="act(b, 'stop')">Confirmer l'arrêt</button>
      </div>
      <div v-else class="flex gap-1.5">
        <button type="button" class="flex-1 rounded-lg bg-[#e7f0fe] py-2 text-sm font-bold text-[#1877f2]" @click="emit('relaunch', b)">🔁 Relancer le même boost</button>
        <template v-if="deletable(b)">
          <button v-if="confirmDelete !== b.id" type="button" class="rounded-lg bg-slate-100 px-3 py-2 text-sm font-bold" aria-label="Supprimer ce boost" @click="confirmDelete = b.id">🗑</button>
          <button v-else type="button" class="rounded-lg bg-red-600 px-3 py-2 text-sm font-bold text-white" :disabled="busy === b.id" @click="remove(b)">Supprimer</button>
        </template>
      </div>
    </article>
  </div>
</template>
