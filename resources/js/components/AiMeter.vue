<script setup>
import { computed } from 'vue'
import { aiExhausted } from '../lib/aiQuota'
import { openRecharge } from '../lib/credits'
import { useAuthStore } from '../stores/auth'

/**
 * À côté d'un bouton de l'assistant IA : les actions du mois (orange quand il en reste peu) et celles du solde ;
 * plus rien : un message rouge et le bouton qui ouvre la recharge du solde (commandes et IA ensemble).
 */
const auth = useAuthStore()
const ai = computed(() => auth.profile?.ai ?? null)
const exhausted = computed(() => aiExhausted(ai.value))
const low = computed(() => ai.value?.limit > 0 && ai.value.limit - ai.value.used <= Math.max(1, Math.ceil(ai.value.limit / 10)) && !ai.value.credits)
</script>

<template>
  <div v-if="ai?.enabled && exhausted" class="space-y-2 rounded-xl border border-red-200 bg-red-50 p-3 text-sm">
    <p class="font-semibold text-red-800">
      Plus d'actions IA. Recharge ton solde : commandes et IA ensemble.
    </p>
    <button type="button" class="btn-brand w-full sm:w-auto" @click="openRecharge()">Recharger mon solde</button>
  </div>
  <p v-else-if="ai?.enabled" class="text-xs" :class="low ? 'font-semibold text-amber-700' : 'text-slate-500'">
    IA : {{ ai.used }}{{ ai.limit !== null ? ` / ${ai.limit}` : '' }} ce mois<template v-if="ai.credits"> · {{ ai.credits }} dans le solde</template><template v-if="low"> · bientôt épuisé</template>
  </p>
</template>
