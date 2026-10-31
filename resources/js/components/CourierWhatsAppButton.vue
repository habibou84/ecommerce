<script setup>
import { ref } from 'vue'
import http, { errorMessage } from '../lib/http'

// Envoie au livreur, depuis le WhatsApp du marchand, ses courses du jour et un lien qui l'ouvre dans son
// application sans mot de passe (lien personnel, un seul usage).
const props = defineProps({
  courierId: { type: Number, required: true },
  label: { type: String, default: 'Envoyer ses courses sur WhatsApp' },
  small: { type: Boolean, default: false },
})
const busy = ref(false)
const error = ref('')

async function send() {
  busy.value = true
  error.value = ''
  // La fenêtre est ouverte tout de suite (sinon le navigateur la bloque), puis dirigée vers WhatsApp
  const win = window.open('', '_blank')
  try {
    const { data } = await http.post(`/couriers/${props.courierId}/access-link`)
    if (win) win.location.href = data.data.whatsapp_url
    else window.location.href = data.data.whatsapp_url
  } catch (e) {
    win?.close()
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <span class="inline-flex flex-col gap-1">
    <button type="button" :class="small ? 'btn-ghost px-2 py-1 text-emerald-700' : 'btn-secondary'" :disabled="busy" @click="send">
      <span aria-hidden="true">🟢</span> {{ busy ? 'Préparation…' : label }}
    </button>
    <span v-if="error" class="text-xs text-red-600">{{ error }}</span>
  </span>
</template>
