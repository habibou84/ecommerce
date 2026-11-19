<script setup>
// Connexion au lien commun : vers sa tournée (boutique d'accueil), ou son espace s'il n'a pas encore de boutique.
import { reactive, ref } from 'vue'
import AuthShell from '../../../components/AuthShell.vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import PasswordInput from '../../../components/PasswordInput.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'

const form = reactive({ phone: '', password: '' })
const errors = ref({})
const error = ref('')
const busy = ref(false)

async function submit() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    const { data } = await http.post('/courier-space/login', form)
    window.location.assign(data.data.redirect ?? '/livreur')
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
    busy.value = false
  }
}
</script>

<template>
  <AuthShell title="Espace livreur" subtitle="Connectez-vous avec votre numéro.">
    <Alert v-if="error" class="mb-4">{{ error }}</Alert>
    <form class="space-y-4" @submit.prevent="submit">
      <Field id="l-phone" label="Numéro WhatsApp" :error="errors.phone">
        <input id="l-phone" v-model="form.phone" class="input" type="tel" inputmode="tel" autocomplete="tel" required autofocus />
      </Field>
      <Field id="l-password" label="Mot de passe" :error="errors.password">
        <PasswordInput id="l-password" v-model="form.password" class="input" autocomplete="current-password" required />
      </Field>
      <button class="btn-brand w-full" :disabled="busy">{{ busy ? 'Connexion…' : 'Se connecter' }}</button>
      <p class="text-center text-sm"><RouterLink to="/mot-de-passe-oublie" class="text-emerald-700 hover:underline">Mot de passe oublié ?</RouterLink></p>
    </form>
    <template #footer>
      Pas encore inscrit ? <RouterLink to="/livreur/inscription" class="font-medium text-emerald-700 hover:underline">Devenir livreur</RouterLink>
    </template>
  </AuthShell>
</template>
