<script setup>
import { ref } from 'vue'
import Icon from './Icon.vue'
import WhatsAppLink from './WhatsAppLink.vue'
import { useAuthStore } from '../stores/auth'

/**
 * WhatsApp pendant un live (page « Vendre en live » et écran du live). Pas relié : ce que le client ne peut pas faire
 * sans lui, et la liaison sur place (QR code ou numéro) pour qui gère la boutique. Relié : une ligne verte.
 * « status » : official (Meta), link (relié par QR code) ou null.
 */
defineProps({ status: { type: String, default: null } })
const emit = defineEmits(['linked'])
const auth = useAuthStore()
const linking = ref(false)

function onLinked() {
  linking.value = false
  emit('linked')
}
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
        <p class="mt-1 text-sm text-slate-600">Votre WhatsApp n'est pas encore relié : les clients peuvent seulement réserver par le lien du live.</p>
      </div>
    </div>
    <p class="mt-4 text-sm font-semibold">Une fois votre WhatsApp relié :</p>
    <ul class="mt-2 space-y-2 text-sm text-slate-700">
      <li class="flex items-start gap-2"><Icon name="check" :size="17" class="mt-0.5 shrink-0 text-emerald-700" />le client envoie le code (« A2 », « A2 x3 ») ou une capture d'écran, l'article est réservé tout seul ;</li>
      <li class="flex items-start gap-2"><Icon name="check" :size="17" class="mt-0.5 shrink-0 text-emerald-700" />son quartier lui est demandé automatiquement ;</li>
      <li class="flex items-start gap-2"><Icon name="check" :size="17" class="mt-0.5 shrink-0 text-emerald-700" />la commande se crée, sans rien noter.</li>
    </ul>

    <template v-if="auth.can('store.manage') && auth.profile?.whatsapp_qr === true">
      <div v-if="linking" class="mt-4"><WhatsAppLink @linked="onLinked" /></div>
      <template v-else>
        <button type="button" class="btn-brand mt-4 w-full sm:w-auto" @click="linking = true"><Icon name="whatsapp" :size="18" />Relier mon WhatsApp</button>
        <p class="mt-2 text-xs text-slate-500">Une minute : on scanne un QR code avec le téléphone de la boutique, comme pour WhatsApp Web, ou on saisit un code reçu par le numéro.</p>
      </template>
    </template>
    <p v-else-if="auth.profile?.whatsapp_qr !== true" class="mt-4 rounded-xl bg-slate-50 p-3 text-sm text-slate-700">Connectez le WhatsApp de la boutique dans Réglages › WhatsApp (« Connecter WhatsApp avec Facebook »).</p>
    <p v-else class="mt-4 rounded-xl bg-slate-50 p-3 text-sm text-slate-700">Demandez au responsable de la boutique de relier le WhatsApp (menu Messages).</p>
  </section>
</template>
