<script setup>
import { money } from '../lib/format'

/**
 * Paiement par le lien marchand Wave : montant, bouton vers Wave (téléphone) ou QR code (ordinateur), puis
 * « J'ai payé » (le validateur est prévenu). Même panneau pour l'abonnement et les recharges de l'assistant.
 */
defineProps({
  invoice: { type: Object, required: true },
  link: { type: String, default: null },
  phone: { type: String, default: '' },
  qrUrl: { type: String, default: null },
  claiming: { type: Boolean, default: false },
})
const emit = defineEmits(['claim'])
</script>

<template>
  <div class="space-y-5 text-sm">
    <div class="rounded-xl bg-slate-50 p-4 text-center ring-1 ring-slate-200">
      <p class="text-2xl font-bold">{{ money(invoice.amount) }} à payer</p>
      <p v-if="phone" class="mt-1 text-slate-600">Depuis le numéro Wave {{ phone }}</p>
      <p class="mt-1 text-xs text-slate-500">{{ invoice.plan }}<template v-if="invoice.kind !== 'ai_pack'"> ({{ invoice.cycle_label }})</template> · facture {{ invoice.number }}</p>
    </div>

    <section class="space-y-3">
      <h3 class="flex items-center gap-2 font-semibold"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-sky-600 text-xs text-white">1</span>Payez avec Wave</h3>
      <!-- Téléphone : le bouton d'abord ; ordinateur : le QR code d'abord (on paie avec son téléphone) -->
      <div class="flex flex-col gap-3">
        <a v-if="link" :href="link" target="_blank" rel="noopener" class="order-1 flex w-full justify-center rounded-lg bg-sky-600 px-4 py-3 font-semibold text-white hover:bg-sky-700 md:order-2">Cliquez ici pour payer avec Wave</a>
        <div v-if="qrUrl" class="order-2 flex flex-col items-center gap-2 md:order-1">
          <p class="text-slate-600">{{ link ? 'ou scannez ce code QR avec l\'application Wave' : 'Scannez ce code QR avec l\'application Wave' }}</p>
          <img :src="qrUrl" alt="Code QR Wave de Jibiat Marchand" class="h-52 w-52 rounded-lg bg-white object-contain ring-1 ring-slate-200" />
        </div>
      </div>
    </section>

    <section class="space-y-2 border-t border-slate-100 pt-4">
      <h3 class="flex items-center gap-2 font-semibold"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-700 text-xs text-white">2</span>Confirmez votre paiement</h3>
      <p class="text-slate-600">Une fois le paiement fait dans Wave :</p>
      <button class="w-full rounded-lg bg-emerald-700 px-4 py-3 font-semibold text-white hover:bg-emerald-800 disabled:opacity-60" :disabled="claiming" @click="emit('claim')">{{ claiming ? 'Envoi…' : 'Cliquez ici pour confirmer votre paiement' }}</button>
      <p class="text-xs text-slate-500">Nous vérifions la réception, puis votre {{ invoice.kind === 'ai_pack' ? 'recharge est ajoutée' : 'offre est activée' }}.</p>
    </section>
  </div>
</template>
