<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import AuthShell from '../../components/AuthShell.vue'
import Alert from '../../components/Alert.vue'
import Field from '../../components/Field.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { useConsoleStore } from '../../stores/console'

// Mot de passe, puis code reçu par SMS
const form = reactive({ phone: '', password: '', code: '' })
const verification = ref(null)
const demoCode = ref(null)
const errors = ref({})
const error = ref('')
const busy = ref(false)
const router = useRouter()
const store = useConsoleStore()

async function submit() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    if (!verification.value) {
      const { data } = await http.post('/console/auth/login', { phone: form.phone, password: form.password })
      verification.value = data.verification
      demoCode.value = data.demo_code ?? null
    } else {
      store.admin = (await http.post('/console/auth/verify', { token: verification.value.token, code: form.code })).data.data
      router.push('/console')
    }
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthShell title="Console Jibiat Marchand" :subtitle="verification ? `Code envoyé par SMS au ${verification.phone}.` : 'Réservée à l\'équipe de la plateforme.'">
    <Alert v-if="error" class="mb-4">{{ error }}</Alert>
    <form class="space-y-4" @submit.prevent="submit">
      <template v-if="!verification">
        <Field id="phone" label="Numéro de téléphone" :error="errors.phone"><input id="phone" v-model="form.phone" class="input" type="tel" autocomplete="tel" required autofocus /></Field>
        <Field id="password" label="Mot de passe" :error="errors.password"><input id="password" v-model="form.password" class="input" type="password" autocomplete="current-password" required /></Field>
      </template>
      <Field v-else id="code" label="Code reçu par SMS" :error="errors.code" :hint="demoCode ? `Démonstration : ${demoCode}` : ''">
        <input id="code" v-model="form.code" class="input text-center text-lg tracking-widest" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus />
      </Field>
      <button class="btn-brand w-full" :disabled="busy">{{ busy ? '…' : verification ? 'Valider' : 'Continuer' }}</button>
    </form>
  </AuthShell>
</template>
