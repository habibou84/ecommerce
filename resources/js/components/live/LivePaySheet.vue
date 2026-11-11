<script setup>
import { computed, ref } from 'vue'
import http, { errorMessage } from '../../lib/http'
import { money } from '../../lib/format'

/**
 * Recharger le crédit live (montant proposé ou autre), ou lives illimités 30 jours, payés sur la page PayDunya
 * (Orange Money, MTN, Moov, Wave, carte). Au retour, la page du live vérifie le paiement.
 */
const props = defineProps({
  billing: { type: Object, required: true },
})
const emit = defineEmits(['close'])
const choice = ref(props.billing.recharges?.[1] ?? 5000)
const other = ref('')
const busy = ref(false)
const error = ref('')

// Le prix d'un live dépend de ses commandes (palier) : on montre combien de commandes en live un montant couvre à peu
// près, au palier 2 (le plus courant : 11 à 30 commandes pour 500 F par défaut)
const tier2 = computed(() => props.billing.tiers?.[1] ?? { from: 11, to: 30, price: 500 })
const ordersFor = (value) => Math.floor(value / (tier2.value.price || 500)) * (tier2.value.to ?? 30)
const amount = computed(() => (choice.value === 'other' ? Math.round(Number(other.value) || 0) : choice.value))
const isMonth = computed(() => choice.value === 'month')

async function pay() {
  busy.value = true
  error.value = ''
  try {
    const body = isMonth.value ? { kind: 'live_month' } : { kind: 'live_credit', amount: amount.value }
    const { data } = await http.post('/lives/billing/checkout', body)
    window.location.href = data.data.checkout_url
  } catch (e) {
    error.value = errorMessage(e)
    busy.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-[60] flex items-end bg-slate-950/60" @click.self="emit('close')">
    <div class="max-h-[92vh] w-full overflow-y-auto rounded-t-[24px] bg-white px-4 pt-2.5 pb-[max(1rem,env(safe-area-inset-bottom))] text-slate-900" role="dialog" aria-modal="true" aria-label="Recharger le crédit live">
      <div class="mx-auto mb-2 h-1.5 w-10 rounded-full bg-slate-300" />
      <div class="flex items-center gap-2">
        <b class="flex-1 text-[18px]">Recharger le crédit live</b>
        <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-slate-100 text-lg font-black" aria-label="Fermer" @click="emit('close')">✕</button>
      </div>
      <div class="mt-2 rounded-2xl p-3" :class="billing.credit < billing.min_credit ? 'bg-orange-50 text-orange-900' : 'bg-emerald-50 text-emerald-900'">
        <b class="block text-[16px]">Crédit : {{ money(billing.credit) }}</b>
        <span v-if="billing.credit < billing.min_credit" class="text-[13.5px]">Il faut au moins {{ money(billing.min_credit) }} pour ouvrir les commandes du live.</span>
      </div>
      <p class="mt-3 text-[15px] font-black">Combien ?</p>
      <p class="text-[12.5px] text-slate-500">Chaque live coûte selon ses commandes<template v-if="billing.tiers?.length"> : {{ billing.tiers.filter((t) => t.to === null || t.to >= t.from).map((t) => `${t.to === null ? `plus de ${t.from - 1}` : `${t.from} à ${t.to}`} = ${t.price ? money(t.price) : 'gratuit'}`).join(' · ') }}</template>.</p>
      <div class="mt-1.5 grid grid-cols-2 gap-2">
        <button v-for="value in billing.recharges" :key="value" type="button" class="rounded-2xl border-[2.5px] px-2 py-2.5 text-center" :class="choice === value ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200'" @click="choice = value">
          <b class="block text-[17px]">{{ money(value) }}</b><small class="text-[12px] text-slate-500">jusqu’à {{ ordersFor(value) }} commandes</small>
        </button>
        <button type="button" class="rounded-2xl border-[2.5px] px-2 py-2.5 text-center" :class="choice === 'other' ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200'" @click="choice = 'other'">
          <b class="block text-[17px]">Autre</b><small class="text-[12px] text-slate-500">montant</small>
        </button>
      </div>
      <input v-if="choice === 'other'" v-model="other" type="number" inputmode="numeric" :min="billing.min_credit" step="500" class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-[16px]" :placeholder="`Au moins ${money(billing.min_credit)}`" aria-label="Montant">
      <button type="button" class="mt-3 flex w-full items-center gap-3 rounded-2xl border-[2.5px] px-3 py-3 text-left" :class="isMonth ? 'border-slate-900 bg-slate-50' : 'border-slate-200'" @click="choice = 'month'">
        <span class="min-w-0 flex-1"><b class="block text-[16px]">Ou : lives illimités 30 jours</b><small class="block text-[12.5px] text-slate-500">Sans compter les commandes</small></span>
        <b class="shrink-0 text-[17px]">{{ money(billing.month_price) }}</b>
      </button>
      <p v-if="error" class="mt-2 text-sm font-bold text-red-700">{{ error }}</p>
      <button type="button" class="mt-3 block w-full rounded-2xl bg-slate-900 py-3.5 text-[17px] font-black text-white disabled:opacity-60" :disabled="busy || (!isMonth && amount < billing.min_credit)" @click="pay">
        {{ busy ? 'Ouverture du paiement…' : `Payer ${money(isMonth ? billing.month_price : amount)}` }}
      </button>
      <p class="mt-2 text-center text-[12.5px] text-slate-500">Orange Money · MTN · Moov · Wave · carte, sur la page sécurisée PayDunya.</p>
    </div>
  </div>
</template>
