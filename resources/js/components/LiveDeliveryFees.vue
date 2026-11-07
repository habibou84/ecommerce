<script setup>
import DeliveryPrices from './DeliveryPrices.vue'

/**
 * « Préparer un live » › Prix de livraison : les prix à toucher (ville, communes, expéditions, livraison offerte),
 * enregistrés à chaque toucher. C'est ce prix qui est mis dans la commande de la cliente.
 */
const emit = defineEmits(['close', 'done'])
</script>

<template>
  <div class="fixed inset-0 z-50 flex flex-col bg-slate-50" role="dialog" aria-modal="true" aria-label="Prix de livraison">
    <header class="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3">
      <h2 class="text-[18px] font-black">Prix de livraison</h2>
      <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-slate-100 text-lg" aria-label="Fermer" @click="emit('close')">✕</button>
    </header>
    <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
      <DeliveryPrices />
      <p class="mt-3 px-1 text-[13px] text-slate-600">C'est toujours ce prix qui est mis dans la commande, même si l'entreprise de livraison a un autre prix.</p>
    </div>
    <footer class="border-t border-slate-200 bg-white p-3">
      <button type="button" class="w-full rounded-2xl bg-emerald-600 py-3.5 text-[16px] font-black text-white" @click="emit('done')">C'est bon ✓</button>
    </footer>
  </div>
</template>
