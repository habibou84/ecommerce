<script setup>
import PasswordInput from '../../components/PasswordInput.vue'
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import AuthShell from '../../components/AuthShell.vue'
import Alert from '../../components/Alert.vue'
import CodeStep from '../../components/CodeStep.vue'
import Field from '../../components/Field.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { useConsoleStore } from '../../stores/console'

// Mot de passe, puis code de l'application d'authentification (ou code de secours). Premier accès seulement : code
// reçu par e-mail ou preuve du numéro (WhatsApp, SMS), puis activation de l'application.
const form = reactive({ phone: '', password: '' })
const pending = ref(null)
const emailStep = ref(null) // { token, email }
const totpStep = ref(null) // { token }
const recovery = ref(false)
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
    if (data.method === 'totp') {
      totpStep.value = data
      recovery.value = false
      code.value = ''
    } else if (data.method === 'email') {
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
    await (totpStep.value
      ? http.post('/console/auth/totp', { token: totpStep.value.token, code: code.value })
      : http.post('/console/auth/email', { token: emailStep.value.token, code: code.value }))
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
    router.push(store.admin.two_factor ? '/console' : '/console/deux-etapes')
  } catch (e) {
    error.value = errorMessage(e)
    pending.value = null
  }
}
</script>

<template>
  <AuthShell title="Console Jibiat Marchand" :subtitle="totpStep ? 'Validation en deux étapes' : emailStep ? `Code envoyé à ${emailStep.email}.` : pending ? `Vérifiez le numéro ${pending.verification.phone}.` : 'Réservée à l\'équipe de la plateforme.'">
    <Alert v-if="error" class="mb-4">{{ error }}</Alert>
    <form v-if="totpStep" class="space-y-4" @submit.prevent="verifyEmail">
      <Field v-if="!recovery" id="code" label="Code de l'application d'authentification" :error="errors.code" hint="Les 6 chiffres affichés dans Google Authenticator ou Microsoft Authenticator.">
        <input id="code" v-model="code" class="input text-center text-2xl font-bold tracking-[0.4em]" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus />
      </Field>
      <Field v-else id="code" label="Code de secours" :error="errors.code" hint="Un des codes notés à l'activation (ex. ab3cd-9xk2p). Chacun ne sert qu'une fois.">
        <input id="code" v-model="code" class="input text-center font-mono text-xl font-bold" autocomplete="off" autocapitalize="off" maxlength="20" required autofocus />
      </Field>
      <button class="btn-brand w-full" :disabled="busy || code.length < 6">{{ busy ? '…' : 'Ouvrir la console' }}</button>
      <div class="flex flex-wrap justify-between gap-2 text-sm">
        <button type="button" class="text-slate-600 hover:underline" @click="recovery = !recovery; code = ''">{{ recovery ? 'Utiliser l\'application' : 'Téléphone perdu ? Code de secours' }}</button>
        <button type="button" class="text-slate-600 hover:underline" @click="totpStep = null">Retour</button>
      </div>
      <p v-if="recovery" class="text-xs text-slate-500">Plus aucun code de secours ? Un autre administrateur peut réinitialiser votre application (Équipe).</p>
    </form>
    <form v-else-if="emailStep" class="space-y-4" @submit.prevent="verifyEmail">
      <Field id="code" label="Code reçu par e-mail" :error="errors.code" hint="6 chiffres, valable 10 minutes. Regardez aussi dans les spams.">
        <input id="code" v-model="code" class="input text-center text-2xl font-bold tracking-[0.4em]" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus />
      </Field>
      <button class="btn-brand w-full" :disabled="busy || code.length < 6">{{ busy ? '…' : 'Ouvrir la console' }}</button>
      <div class="flex flex-wrap justify-between gap-2 text-sm">
        <button type="button" class="text-emerald-700 hover:underline" :disabled="busy" @click="submit('email')">Renvoyer un code</button>
        <button type="button" class="text-slate-600 hover:underline" :disabled="busy" @click="submit('phone')">Vérifier plutôt par mon numéro</button>
      </div>
    </form>
    <CodeStep v-else-if="pending" :verification="pending.verification" :demo-code="pending.demo_code" submit-label="Ouvrir la console" @done="done" />
    <form v-else class="space-y-4" @submit.prevent="submit()">
      <Field id="phone" label="Numéro de téléphone" :error="errors.phone"><input id="phone" v-model="form.phone" class="input" type="tel" autocomplete="tel" required autofocus /></Field>
      <Field id="password" label="Mot de passe" :error="errors.password"><PasswordInput id="password" v-model="form.password" class="input" autocomplete="current-password" required /></Field>
      <button class="btn-brand w-full" :disabled="busy">{{ busy ? '…' : 'Continuer' }}</button>
      <p class="text-center text-sm"><RouterLink to="/console/mot-de-passe-oublie" class="text-emerald-700 hover:underline">Mot de passe oublié ? (ou premier accès)</RouterLink></p>
    </form>
  </AuthShell>
</template>
