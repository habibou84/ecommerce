<script setup>
import { computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { credits, loadCredits } from '../../lib/credits'
import { offerLink, offerState } from '../../lib/offre'
import { useAuthStore } from '../../stores/auth'

/**
 * En-tête : « Pro · 🧾 42 · ✨ 80 », mène à « Mon offre Jibiat ». Le nom de l'offre passe en orange ou en rouge quand
 * l'abonnement demande d'agir, le nombre de commandes quand il en reste peu. Sans solde compté : le nom de l'offre seul.
 */
const auth = useAuthStore()
const state = computed(() => offerState(auth.store))
onMounted(() => credits.data || loadCredits())

const PLAN_TONE = { red: 'bg-red-50 text-red-700', orange: 'bg-ocre-50 text-ocre-800' }
</script>

<template>
  <RouterLink v-if="auth.store" :to="offerLink(state)" class="flex h-10 shrink-0 items-center gap-1 rounded-full bg-white pl-1 pr-1 text-xs font-extrabold tabular-nums shadow-soft" :aria-label="`Mon offre Jibiat : ${state.line}`">
    <span class="rounded-full px-2 py-1" :class="PLAN_TONE[state.planLevel] ?? 'text-emerald-800'">{{ state.plan }}</span>
    <template v-if="state.orders !== null">
      <span class="rounded-full px-2 py-1" :class="state.soldeLevel === 'red' ? 'bg-red-50 text-red-700' : state.soldeLevel ? 'bg-ocre-50 text-ocre-700' : 'bg-emerald-50 text-emerald-700'"><span aria-hidden="true">🧾 </span>{{ state.orders }}</span>
      <span class="hidden rounded-full bg-emerald-50 px-2 py-1 text-emerald-700 sm:inline"><span aria-hidden="true">✨ </span>{{ state.ai }}</span>
    </template>
  </RouterLink>
</template>
