<script setup>
import { onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { credits, loadCredits } from '../../lib/credits'

/** En-tête : « 🧾 42 · ✨ 80 », orange quand il reste peu de commandes ; mène à « Mon solde ». Caché si rien n'est compté. */
onMounted(() => credits.data || loadCredits())
</script>

<template>
  <RouterLink v-if="credits.data?.paid" to="/gestion/solde" class="flex shrink-0 items-center gap-1 text-xs font-extrabold tabular-nums" aria-label="Mon solde">
    <span class="rounded-full px-2 py-1" :class="credits.data.low ? 'bg-ocre-50 text-ocre-700' : 'bg-emerald-50 text-emerald-700'">🧾 {{ credits.data.month_until ? '∞' : credits.data.orders }}</span>
    <span class="rounded-full bg-emerald-50 px-2 py-1 text-emerald-700">✨ {{ credits.data.ai }}</span>
  </RouterLink>
</template>
