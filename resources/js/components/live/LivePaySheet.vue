<script setup>
import { ref } from 'vue'
import http, { errorMessage } from '../../lib/http'
import { money } from '../../lib/format'

/**
 * Lives offerts utilisés : 24 h ou 30 jours de lives, payés sur la page PayDunya (Orange Money, MTN, Moov, Wave,
 * carte). Au retour, la page du live vérifie le paiement et démarre le live.
 */
const props = defineProps({
  billing: { type: Object, required: true },
})
const emit = defineEmits(['close'])
const kind = ref('live_month')
const busy = ref(false)
const error = ref('')

async function pay() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post('/lives/billing/checkout', { kind: kind.value })
    window.location.href = data.data.checkout_url
  } catch (e) {
    error.value = errorMessage(e)
    busy.value = false
  }
}
const days = () => Math.floor(props.billing.prices.month / props.billing.prices.day) + 1
</script>

<template>
  <div class="fixed inset-0 z-[60] flex items-end bg-slate-950/60" @click.self="emit('close')">
    <div class="w-full rounded-t-[24px] bg-white px-4 pt-2.5 pb-[max(1rem,env(safe-area-inset-bottom))] text-slate-900" role="dialog" aria-modal="true" aria-label="Payer vos lives">
      <div class="mx-auto mb-2 h-1.5 w-10 rounded-full bg-slate-300" />
      <div class="flex items-center gap-2">
        <b class="flex-1 text-[18px]">Payer vos lives</b>
        <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-slate-100 text-lg font-black" aria-label="Fermer" @click="emit('close')">✕</button>
      </div>
      <p class="mt-1 text-[14px] text-slate-600">Vos {{ billing.free_lives }} lives offerts sont utilisés.</p>
      <button v-for="[key, title, hint, price] in [['live_day', '24 heures', 'Autant de lives que vous voulez pendant 24 h', billing.prices.day], ['live_month', '30 jours', `Lives illimités · rentable dès ${days()} jours de live`, billing.prices.month]]" :key="key"
              type="button" class="mt-2 flex w-full items-center gap-3 rounded-2xl border-[2.5px] px-3 py-3 text-left" :class="kind === key ? 'border-ocre-600 bg-orange-50' : 'border-slate-200'" @click="kind = key">
        <span class="h-5 w-5 shrink-0 rounded-full border-slate-300" :class="kind === key ? 'border-[6px] border-ocre-600' : 'border-[2.5px]'" />
        <span class="min-w-0 flex-1"><b class="block text-[16px]">{{ title }}</b><small class="block text-[12.5px] text-slate-500">{{ hint }}</small></span>
        <b class="shrink-0 text-[17px]">{{ money(price) }}</b>
      </button>
      <p v-if="error" class="mt-2 text-sm font-bold text-red-700">{{ error }}</p>
      <button type="button" class="mt-3 block w-full rounded-2xl bg-emerald-700 py-3.5 text-[17px] font-black text-white disabled:opacity-60" :disabled="busy" @click="pay">
        {{ busy ? 'Ouverture du paiement…' : `Payer ${money(kind === 'live_month' ? billing.prices.month : billing.prices.day)}` }}
      </button>
      <p class="mt-2 text-center text-[12.5px] text-slate-500">Orange Money · MTN · Moov · Wave · carte, sur la page sécurisée PayDunya. Le live démarre dès le paiement reçu.</p>
    </div>
  </div>
</template>
