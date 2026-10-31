<script setup>
import { reactive, ref } from 'vue'
import http, { errorMessage, fieldErrors } from '../lib/http'

// Création d'un livreur en deux champs, pendant qu'on lui confie une course. Il reçoit ensuite ses courses et un
// lien d'accès sur WhatsApp : pas de mot de passe à créer.
const emit = defineEmits(['created', 'cancel'])
const form = reactive({ name: '', phone: '' })
const errors = ref({})
const error = ref('')
const busy = ref(false)

async function submit() {
  busy.value = true
  error.value = ''
  errors.value = {}
  try {
    const { data } = await http.post('/couriers', { name: form.name.trim(), phone: form.phone })
    emit('created', data.data)
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <form class="space-y-3 rounded-xl border-2 border-emerald-600 bg-emerald-50/50 p-3" @submit.prevent="submit">
    <p class="font-semibold">Nouveau livreur</p>
    <div>
      <label for="new-courier-name" class="label">Son nom</label>
      <input id="new-courier-name" v-model="form.name" class="input" maxlength="80" required autocomplete="off" placeholder="Ex. Moussa Koné" />
      <p v-if="errors.name" class="mt-1 text-sm text-red-600">{{ errors.name }}</p>
    </div>
    <div>
      <label for="new-courier-phone" class="label">Son numéro WhatsApp</label>
      <input id="new-courier-phone" v-model="form.phone" class="input" type="tel" inputmode="tel" required autocomplete="off" placeholder="07 00 00 00 00" />
      <p v-if="errors.phone" class="mt-1 text-sm text-red-600">{{ errors.phone }}</p>
    </div>
    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
    <p class="text-xs text-slate-500">Il recevra ses courses et un lien sur WhatsApp : pas de mot de passe à créer.</p>
    <div class="grid grid-cols-2 gap-2">
      <button type="button" class="btn-secondary" @click="emit('cancel')">Annuler</button>
      <button class="btn-brand" :disabled="busy || !form.name.trim() || !form.phone">{{ busy ? 'Création…' : 'Créer' }}</button>
    </div>
  </form>
</template>
