<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import AuthShell from '../../components/AuthShell.vue'
import Alert from '../../components/Alert.vue'
import CodeStep from '../../components/CodeStep.vue'
import Field from '../../components/Field.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { useConsoleStore } from '../../stores/console'

// Mot de passe, puis preuve du numéro par WhatsApp (message au numéro du support) ou code SMS en dernier recours
const form = reactive({ phone: '', password: '' })
const pending = ref(null)
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
    const { data } = await http.post('/console/auth/login', { phone: form.phone, password: form.password })
    pending.value = data
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

// Numéro prouvé : la session de la console est ouverte par le serveur
async function done() {
  try {
    store.admin = (await http.get('/console/auth/me')).data.data
    router.push('/console')
  } catch (e) {
    error.value = errorMessage(e)
    pending.value = null
  }
}
</script>

<template>
  <AuthShell title="Console Jibiat Marchand" :subtitle="pending ? `Vérifiez le numéro ${pending.verification.phone}.` : 'Réservée à l\'équipe de la plateforme.'">
    <Alert v-if="error" class="mb-4">{{ error }}</Alert>
    <CodeStep v-if="pending" :verification="pending.verification" :demo-code="pending.demo_code" submit-label="Ouvrir la console" @done="done" />
    <form v-else class="space-y-4" @submit.prevent="submit">
      <Field id="phone" label="Numéro de téléphone" :error="errors.phone"><input id="phone" v-model="form.phone" class="input" type="tel" autocomplete="tel" required autofocus /></Field>
      <Field id="password" label="Mot de passe" :error="errors.password"><input id="password" v-model="form.password" class="input" type="password" autocomplete="current-password" required /></Field>
      <button class="btn-brand w-full" :disabled="busy">{{ busy ? '…' : 'Continuer' }}</button>
    </form>
  </AuthShell>
</template>
