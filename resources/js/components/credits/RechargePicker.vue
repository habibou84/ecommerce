<script setup>
import { computed, ref } from 'vue'
import http, { errorMessage } from '../../lib/http'
import { money } from '../../lib/format'

/**
 * Recharger le solde : un pack (commandes + IA incluse) ou l'illimité 30 jours (IA incluse), payé sur la page
 * PayDunya (Orange Money, MTN, Moov, Wave, carte). Au retour, la page « Mon solde » vérifie le paiement.
 *
 * @prop data  solde (GET /credits) : packs, illimité
 */
const props = defineProps({ data: { type: Object, required: true } })
const mode = ref('pack')
const pack = ref(props.data.packs.find((p) => p.advised)?.index ?? props.data.packs[0]?.index)
const busy = ref(false)
const error = ref('')
const price = computed(() => (mode.value === 'month' ? props.data.month.price : props.data.packs.find((p) => p.index === pack.value)?.price ?? 0))
const nb = (n) => n.toLocaleString('fr-FR')

async function pay() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post('/credits/checkout', mode.value === 'month' ? { kind: 'month' } : { kind: 'pack', pack: pack.value })
    window.location.href = data.data.checkout_url
  } catch (e) {
    error.value = errorMessage(e)
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-2.5">
    <div class="grid grid-cols-2 gap-2">
      <button type="button" class="rounded-xl border-2 py-2.5 text-sm font-extrabold" :class="mode === 'pack' ? 'border-emerald-600 bg-emerald-50 text-emerald-800' : 'border-slate-200'" @click="mode = 'pack'">🧾 Pack</button>
      <button type="button" class="rounded-xl border-2 py-2.5 text-sm font-extrabold" :class="mode === 'month' ? 'border-emerald-600 bg-emerald-50 text-emerald-800' : 'border-slate-200'" @click="mode = 'month'">♾️ Illimité 30 j</button>
    </div>
    <template v-if="mode === 'pack'">
      <button v-for="p in data.packs" :key="p.index" type="button" class="flex w-full items-center gap-3 rounded-xl border-2 px-3 py-2.5 text-left"
        :class="pack === p.index ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200'" @click="pack = p.index">
        <span class="min-w-0 flex-1">
          <b class="font-display text-base">{{ nb(p.orders) }} commandes</b>
          <span v-if="p.advised" class="ml-1 rounded-full bg-emerald-700 px-2 py-0.5 text-xs font-extrabold text-white">Conseillé</span>
          <span class="block text-xs text-slate-500">+ {{ nb(p.ai) }} actions IA</span>
        </span>
        <span class="text-right"><b class="block font-display text-base tabular-nums">{{ money(p.price) }}</b><span class="text-xs text-slate-500">{{ p.unit }} F / commande</span></span>
      </button>
    </template>
    <div v-else class="rounded-xl border-2 border-emerald-600 bg-emerald-50 px-3 py-2.5">
      <div class="flex items-center gap-3">
        <span class="min-w-0 flex-1"><b class="font-display text-base">Illimité 30 jours</b><span class="block text-xs text-slate-500">Commandes illimitées · {{ nb(data.month.ai) }} actions IA</span></span>
        <b class="font-display text-base tabular-nums">{{ money(data.month.price) }}</b>
      </div>
    </div>
    <p v-if="error" class="rounded-xl bg-red-50 p-2.5 text-sm font-bold text-red-700">{{ error }}</p>
    <button type="button" class="btn-brand w-full justify-center py-3 text-base font-black" :disabled="busy" @click="pay">{{ busy ? 'Ouverture du paiement…' : `Payer ${money(price)}` }}</button>
    <p class="text-center text-xs text-slate-500">Orange Money, MTN, Moov, Wave ou carte.</p>
  </div>
</template>
