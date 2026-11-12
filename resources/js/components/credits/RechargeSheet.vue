<script setup>
import { RouterLink } from 'vue-router'
import Modal from '../Modal.vue'
import RechargePicker from './RechargePicker.vue'
import { credits } from '../../lib/credits'

/**
 * Fenêtre « Recharger mon solde », ouverte de n'importe quel écran (plus d'IA, live qui ne s'ouvre pas, boost) :
 * la raison en rouge, le solde, puis les packs et l'illimité.
 */
</script>

<template>
  <Modal title="Recharger mon solde" @close="credits.open = false">
    <div class="space-y-3">
      <p v-if="credits.message" class="rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700">{{ credits.message }}</p>
      <p v-if="!credits.data" class="p-4 text-center text-sm text-slate-500">Chargement…</p>
      <template v-else>
        <div class="grid grid-cols-2 gap-2">
          <div class="rounded-xl bg-slate-50 p-3"><b class="block font-display text-2xl tabular-nums" :class="credits.data.orders < 0 ? 'text-red-700' : ''">{{ credits.data.orders }}</b><span class="text-xs text-slate-500">commandes</span></div>
          <div class="rounded-xl bg-slate-50 p-3"><b class="block font-display text-2xl tabular-nums">{{ credits.data.ai }}</b><span class="text-xs text-slate-500">actions IA</span></div>
        </div>
        <RechargePicker :data="credits.data" />
        <RouterLink to="/gestion/solde" class="block text-center text-sm font-bold text-emerald-700 underline" @click="credits.open = false">Voir mon solde et ce qui est compté</RouterLink>
      </template>
    </div>
  </Modal>
</template>
