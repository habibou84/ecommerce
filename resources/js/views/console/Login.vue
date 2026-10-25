<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import AuthShell from '../../components/AuthShell.vue'
import Alert from '../../components/Alert.vue'
import CodeStep from '../../components/CodeStep.vue'
import Field from '../../components/Field.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { useConsoleStore } from '../../stores/console'

// Mot de passe, puis code reçu par e-mail ; sans e-mail (ou à la demande) : preuve du numéro par WhatsApp
// (message au numéro du support) ou code SMS en dernier recours
const form = reactive({ phone: '', password: '' })
const pending = ref(null)
const emailStep = ref(null) // { token, email }
const code = ref('')
const errors = ref({})
const error = ref('')
const busy = ref(false)
const router = useRouter()
const store = useConsoleStore()

async function submit(channel = null) {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    const { data } = await http.post('/console/auth/login', { phone: form.phone, password: form.password, channel })
    if (data.method === 'email') {
      emailStep.value = data
      code.value = ''
    } else {
      emailStep.value = null
      pending.value = data
    }
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function verifyEmail() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    await http.post('/console/auth/email', { token: emailStep.value.token, code: code.value })
    await done()
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

// Numéro prouvé ou code juste : la session de la console est ouverte par le serveur
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
  <AuthShell title="Console Jibiat Marchand" :subtitle="emailStep ? `Code envoyé à ${emailStep.email}.` : pending ? `Vérifiez le numéro ${pending.verification.phone}.` : 'Réservée à l\'équipe de la plateforme.'">
    <Alert v-if="error" class="mb-4">{{ error }}</Alert>
    <form v-if="emailStep" class="space-y-4" @submit.prevent="verifyEmail">
      <Field id="code" label="Code reçu par e-mail" :error="errors.code" hint="6 chiffres, valable 10 minutes. Regardez aussi dans les spams.">
        <input id="code" v-model="code" class="input text-center text-2xl font-bold tracking-[0.4em]" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus />
      </Field>
      <button class="btn-brand w-full" :disabled="busy || code.length < 6">{{ busy ? '…' : 'Ouvrir la console' }}</button>
      <div class="flex flex-wrap justify-between gap-2 text-sm">
        <button type="button" class="text-emerald-700 hover:underline" :disabled="busy" @click="submit('email')">Renvoyer un code</button>
        <button type="button" class="text-slate-600 hover:underline" :disabled="busy" @click="submit('phone')">Recevoir plutôt par WhatsApp</button>
      </div>
    </form>
    <CodeStep v-else-if="pending" :verification="pending.verification" :demo-code="pending.demo_code" submit-label="Ouvrir la console" @done="done" />
    <form v-else class="space-y-4" @submit.prevent="submit()">
      <Field id="phone" label="Numéro de téléphone" :error="errors.phone"><input id="phone" v-model="form.phone" class="input" type="tel" autocomplete="tel" required autofocus /></Field>
      <Field id="password" label="Mot de passe" :error="errors.password"><input id="password" v-model="form.password" class="input" type="password" autocomplete="current-password" required /></Field>
      <button class="btn-brand w-full" :disabled="busy">{{ busy ? '…' : 'Continuer' }}</button>
    </form>
  </AuthShell>
</template>
