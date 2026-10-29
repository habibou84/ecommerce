<script setup>
import Icon from './Icon.vue'
import { useAuthStore } from '../stores/auth'

/**
 * WhatsApp pendant un live (page « Vendre en live » et écran du live). Pas connecté : ce que le client ne peut pas
 * faire sans lui, et le chemin vers le WhatsApp officiel (Réglages › WhatsApp) ; plus de liaison par QR code ici.
 * Connecté : une ligne verte. « status » : official (Meta), link (relié par QR code) ou null.
 */
defineProps({ status: { type: String, default: null } })
const auth = useAuthStore()
</script>

<template>
  <p v-if="status" class="flex items-center gap-2 rounded-2xl bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
    <Icon name="check" :size="18" class="shrink-0" />WhatsApp relié : les commandes envoyées sur WhatsApp pendant le live sont lues toutes seules.
  </p>

  <section v-else class="card border-ocre-100 p-5">
    <div class="flex items-start gap-3">
      <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-ocre-50 text-ocre-700"><Icon name="whatsapp" :size="22" /></span>
      <div class="min-w-0 flex-1">
        <h3 class="text-[17px] font-extrabold leading-snug">Recevez les commandes du live par WhatsApp</h3>
        <p class="mt-1 text-sm text-slate-600">Votre WhatsApp n'est pas encore connecté : les clients peuvent seulement réserver par le lien du live.</p>
      </div>
    </div>
    <p class="mt-4 text-sm font-semibold">Une fois votre WhatsApp connecté :</p>
    <ul class="mt-2 space-y-2 text-sm text-slate-700">
      <li class="flex items-start gap-2"><Icon name="check" :size="17" class="mt-0.5 shrink-0 text-emerald-700" />le client envoie le code (« A2 », « A2 x3 ») ou une capture d'écran, l'article est réservé tout seul ;</li>
      <li class="flex items-start gap-2"><Icon name="check" :size="17" class="mt-0.5 shrink-0 text-emerald-700" />son quartier lui est demandé automatiquement ;</li>
      <li class="flex items-start gap-2"><Icon name="check" :size="17" class="mt-0.5 shrink-0 text-emerald-700" />la commande se crée, sans rien noter.</li>
    </ul>

    <template v-if="auth.can('store.manage')">
      <RouterLink to="/gestion/whatsapp?onglet=messages" class="btn-brand mt-4 w-full sm:w-auto"><Icon name="whatsapp" :size="18" />Connecter le WhatsApp officiel</RouterLink>
      <p class="mt-2 text-xs text-slate-500">En un clic avec Facebook, environ 5 minutes.</p>
    </template>
    <p v-else class="mt-4 rounded-xl bg-slate-50 p-3 text-sm text-slate-700">Demandez au responsable de la boutique de connecter le WhatsApp officiel (Réglages › WhatsApp).</p>
  </section>
</template>
