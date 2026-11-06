<script setup>
import { useAuthStore } from '../stores/auth'

/**
 * « Comment ça marche » du live, en grand et avec peu de mots : le titre, les 4 étapes (WhatsApp d'abord), puis la
 * vidéo sur la même page. WhatsApp se connecte depuis l'étape 1 (ou « Continuer sans WhatsApp ») ; le bouton « Préparer mon live » est porté par la page parente.
 * whatsapp : 'official', 'link' ou null (pas connecté).
 */
defineProps({
  whatsapp: { type: String, default: null },
  video: { type: String, required: true },
  // « Continuer sans WhatsApp » déjà touché
  skipped: Boolean,
})
const emit = defineEmits(['connect', 'skip'])
const auth = useAuthStore()

const steps = [
  ['🛍️', 'Choisissez vos articles', 'Chacun reçoit un code : A1, A2…'],
  ['📌', 'Épinglez la photo', 'Vos articles et leurs codes'],
  ['✅', 'La commande se crée seule', 'Et part au livreur'],
]
</script>

<template>
  <div class="space-y-3">
    <div class="rounded-[24px] bg-gradient-to-br from-ocre-800 via-ocre-600 to-orange-400 px-4 py-5 text-center text-white">
      <h2 class="text-[28px] font-black leading-[1.1]">Vendez en live<br>sans rien noter</h2>
      <p class="mt-2 text-[18px] font-bold leading-snug text-orange-50">Les clientes envoient le code.<br>La commande se crée seule.</p>
    </div>

    <!-- Étape 1 : WhatsApp -->
    <div v-if="whatsapp" class="flex items-center gap-3 rounded-[20px] border-[3px] border-emerald-500 bg-emerald-50 px-3.5 py-3">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-emerald-500 text-[19px] font-black text-white">✓</span>
      <b class="text-[19px] leading-tight text-emerald-900">WhatsApp connecté</b>
    </div>
    <div v-else class="flex items-center gap-3 rounded-[20px] border-[3px] border-[#25d366] bg-white px-3.5 py-3">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-slate-900 text-[19px] font-black text-white">1</span>
      <div class="min-w-0 flex-1">
        <b class="block text-[19px] leading-tight">Connectez WhatsApp</b>
        <span class="text-[14px] text-slate-500">Pour recevoir les commandes</span>
        <template v-if="auth.can('store.manage')">
          <button type="button" class="mt-2 block w-full rounded-xl bg-[#25d366] py-3 text-[16px] font-black text-white" @click="emit('connect')">Connecter WhatsApp</button>
          <button v-if="!skipped" type="button" class="mt-2 block w-full text-center text-[13px] font-bold text-slate-500 underline" @click="emit('skip')">Continuer sans WhatsApp</button>
        </template>
        <span v-else class="mt-1 block text-[13px] text-slate-600">Demandez au responsable de la boutique de le connecter.</span>
      </div>
    </div>

    <div v-for="([icon, title, text], i) in steps" :key="title" class="flex items-center gap-3 rounded-[20px] bg-white px-3.5 py-3 shadow-soft" :class="{ 'opacity-50': !whatsapp && !skipped }">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-slate-900 text-[19px] font-black text-white">{{ i + 2 }}</span>
      <span class="w-11 shrink-0 text-center text-[34px]" aria-hidden="true">{{ icon }}</span>
      <div class="min-w-0"><b class="block text-[19px] leading-tight">{{ title }}</b><span class="text-[14px] text-slate-500">{{ text }}</span></div>
    </div>

    <h3 class="px-1 pt-2 text-[20px] font-black">🎬 En vidéo</h3>
    <video class="mx-auto max-h-[70vh] w-full rounded-[20px] bg-black" controls playsinline preload="metadata" :poster="`${video}.jpg`" :src="`${video}.mp4`" />
  </div>
</template>
