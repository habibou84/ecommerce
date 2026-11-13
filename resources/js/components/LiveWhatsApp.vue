<script setup>
import { useRoute, useRouter } from 'vue-router'
import Icon from './Icon.vue'
import WhatsAppHelp from './WhatsAppHelp.vue'
import { connectWhatsappDirect } from '../lib/whatsappConnect'
import { useAuthStore } from '../stores/auth'

/**
 * WhatsApp pendant un live (page « Vendre en live » et écran du live). Pas connecté : une carte courte (titre, une
 * phrase, un exemple, le bouton qui ouvre directement la connexion avec Facebook, l'aide en cas de blocage).
 * Connecté : une ligne verte. « status » : official (Meta), link (relié par QR code) ou null.
 */
defineProps({ status: { type: String, default: null } })
const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const connect = () => connectWhatsappDirect(router, route.fullPath)
</script>

<template>
  <p v-if="status" class="flex items-center gap-2 rounded-2xl bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
    <Icon name="check" :size="18" class="shrink-0" />WhatsApp relié : les commandes envoyées sur WhatsApp pendant le live sont lues toutes seules.
  </p>

  <section v-else class="card border-2 border-whatsapp p-4">
    <div class="flex items-center gap-2.5">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-700"><Icon name="whatsapp" :size="22" /></span>
      <h3 class="text-[18px] font-black leading-tight">Connectez votre numéro WhatsApp sur Meta</h3>
    </div>
    <p class="mt-2 text-[15px] text-slate-700">Connexion officielle de votre numéro chez Meta : les commandes arrivent, et s'enregistrent toutes seules.</p>
    <div class="mt-2.5 space-y-1.5 rounded-xl bg-[#e7f8ee] p-2 text-[13px]" aria-hidden="true">
      <div class="w-max rounded-lg bg-white px-2 py-0.5 font-bold">A2 taille M</div>
      <div class="ml-auto w-max max-w-[85%] rounded-lg bg-[#d9fdd3] px-2 py-0.5">✅ Réservé ! Ton quartier ?</div>
    </div>

    <button v-if="auth.can('store.manage')" type="button" class="mt-3 block w-full rounded-xl bg-whatsapp py-3 text-[16px] font-black text-white" @click="connect">Connecter WhatsApp</button>
    <p v-else class="mt-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-700">Demande au responsable de la boutique de connecter WhatsApp.</p>

    <WhatsAppHelp class="mt-3" />
  </section>
</template>
