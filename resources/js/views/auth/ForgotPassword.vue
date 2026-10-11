<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AuthShell from '../../components/AuthShell.vue'
import Alert from '../../components/Alert.vue'
import CodeStep from '../../components/CodeStep.vue'
import Field from '../../components/Field.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'

const props = defineProps({ loginPath: { type: String, required: true } })
const router = useRouter()

const phone = ref('')
const errors = ref({})
const error = ref('')
const busy = ref(false)
const pending = ref(null)
const success = ref('')

async function submit() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    const { data } = await http.post('/password/forgot', { phone: phone.value })
    pending.value = data
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

function done(data) {
  if (data.redirect) {
    window.location.assign(data.redirect)
    return
  }
  success.value = data.message
  pending.value = null
}
</script>

<template>
  <AuthShell title="Mot de passe oublié" subtitle="Recevez un code par SMS pour choisir un nouveau mot de passe.">
    <div v-if="success" class="space-y-4">
      <Alert tone="success">{{ success }}</Alert>
      <button class="btn-brand w-full" @click="router.push(props.loginPath)">Se connecter</button>
    </div>

    <CodeStep v-else-if="pending" :verification="pending.verification" :demo-code="pending.demo_code" ask-password submit-label="Changer le mot de passe" @done="done" />

    <form v-else class="space-y-4" @submit.prevent="submit">
      <Alert v-if="error">{{ error }}</Alert>
      <Field id="phone" label="Numéro de téléphone" :error="errors.phone">
        <input id="phone" v-model="phone" class="input" type="tel" inputmode="tel" autocomplete="tel" required autofocus />
      </Field>
      <button class="btn-brand w-full" :disabled="busy">{{ busy ? 'Envoi…' : 'Recevoir un code' }}</button>
    </form>

    <template #footer>
      <RouterLink :to="props.loginPath" class="hover:underline">Retour à la connexion</RouterLink>
    </template>
  </AuthShell>
</template>
