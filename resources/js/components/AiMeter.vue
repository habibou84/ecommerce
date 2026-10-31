<script setup>
import { computed } from 'vue'
import { aiExhausted, openAiRecharge } from '../lib/aiQuota'
import { money } from '../lib/format'
import { useAuthStore } from '../stores/auth'

/**
 * À côté d'un bouton de l'assistant IA : les actions du mois (orange quand il en reste peu) et la réserve achetée ;
 * plus rien : un message rouge et le bouton qui ouvre directement la fenêtre de recharge.
 */
const auth = useAuthStore()
const ai = computed(() => auth.profile?.ai ?? null)
const exhausted = computed(() => aiExhausted(ai.value))
const low = computed(() => ai.value?.limit > 0 && ai.value.limit - ai.value.used <= Math.max(1, Math.ceil(ai.value.limit / 10)) && !ai.value.credits)
const pack = computed(() => ai.value?.pack ?? { actions: 100, price: 1000 })
</script>

<template>
  <div v-if="ai?.enabled && exhausted" class="space-y-2 rounded-xl border border-red-200 bg-red-50 p-3 text-sm">
    <p class="font-semibold text-red-800">
      {{ ai.limit ? `Vos ${ai.limit} actions de l'assistant IA du mois sont utilisées.` : 'L\'assistant IA marche avec des recharges.' }}
      Rechargez pour continuer.
    </p>
    <button v-if="auth.can('billing.manage')" type="button" class="btn-brand w-full sm:w-auto" @click="openAiRecharge()">Recharger {{ pack.actions }} actions · {{ money(pack.price) }}</button>
    <p v-else class="text-slate-700">Demandez au propriétaire ou au gérant de recharger l'assistant.</p>
  </div>
  <p v-else-if="ai?.enabled" class="text-xs" :class="low ? 'font-semibold text-amber-700' : 'text-slate-500'">
    IA : {{ ai.used }}{{ ai.limit !== null ? ` / ${ai.limit}` : '' }} ce mois<template v-if="ai.credits"> · {{ ai.credits }} en réserve</template><template v-if="low"> · bientôt épuisé</template>
  </p>
</template>
