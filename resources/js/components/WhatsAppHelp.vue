<script setup>
import { computed } from 'vue'
import { boot } from '../lib/boot'

// Connexion de WhatsApp chez Meta : en cas de blocage, l'équipe Jibiat Marchand aide (WhatsApp du support)
const url = computed(() => (boot.supportWhatsapp
  ? `https://wa.me/${String(boot.supportWhatsapp).replace(/\D/g, '')}?text=${encodeURIComponent('Bonjour, je bloque pour connecter mon numéro WhatsApp sur Meta.')}`
  : null))
</script>

<template>
  <p class="text-[13.5px] text-slate-600">
    Un blocage chez Meta ?
    <a v-if="url" :href="url" target="_blank" rel="noopener" class="font-bold text-emerald-700 underline">Écris-nous sur WhatsApp</a><template v-else>Écris-nous</template>, on t'aide.
  </p>
</template>
