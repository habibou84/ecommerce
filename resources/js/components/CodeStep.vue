<script setup>
import { onBeforeUnmount, ref } from 'vue'
import http, { errorMessage, fieldErrors } from '../lib/http'
import Alert from './Alert.vue'
import Field from './Field.vue'

// Saisie du code SMS d'une vérification (création de boutique, invitation, mot de passe oublié)
const props = defineProps({
  verification: { type: Object, required: true }, // { token, phone, resend_in }
  demoCode: { type: String, default: null },
  askPassword: { type: Boolean, default: false },
  submitLabel: { type: String, default: 'Valider' },
})
const emit = defineEmits(['done'])

const code = ref('')
const password = ref('')
const errors = ref({})
const error = ref('')
const info = ref('')
const busy = ref(false)
const demo = ref(props.demoCode)
const wait = ref(props.verification.resend_in ?? 60)

const timer = setInterval(() => {
  if (wait.value > 0) wait.value--
}, 1000)
onBeforeUnmount(() => clearInterval(timer))

async function submit() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    const { data } = await http.post(`/verifications/${props.verification.token}/confirm`, {
      code: code.value,
      password: props.askPassword ? password.value : undefined,
    })
    emit('done', data)
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!errors.value.code && !errors.value.password) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function resend() {
  error.value = ''
  info.value = ''
  try {
    const { data } = await http.post(`/verifications/${props.verification.token}/resend`)
    wait.value = data.resend_in ?? 60
    demo.value = data.demo_code ?? demo.value
    info.value = data.message
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="submit">
    <p class="text-sm text-slate-600">
      Nous avons envoyé un code à 6 chiffres par SMS au <strong class="whitespace-nowrap">{{ verification.phone }}</strong>.
    </p>
    <Alert v-if="demo" tone="info">Mode démonstration (aucun SMS envoyé) : le code est <strong>{{ demo }}</strong>.</Alert>
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="info" tone="success">{{ info }}</Alert>

    <Field id="code" label="Code reçu par SMS" :error="errors.code">
      <input
        id="code"
        v-model="code"
        class="input text-center text-2xl tracking-[0.5em]"
        inputmode="numeric"
        autocomplete="one-time-code"
        maxlength="6"
        required
        autofocus
      />
    </Field>

    <Field v-if="askPassword" id="new-password" label="Nouveau mot de passe" :error="errors.password" hint="8 caractères au moins.">
      <input id="new-password" v-model="password" type="password" class="input" autocomplete="new-password" minlength="8" required />
    </Field>

    <button class="btn-brand w-full" :disabled="busy || code.length < 6">{{ busy ? 'Vérification…' : submitLabel }}</button>

    <p class="text-center text-sm text-slate-600">
      <button v-if="wait === 0" type="button" class="font-medium text-emerald-700 hover:underline" @click="resend">Renvoyer un code</button>
      <span v-else>Nouveau code possible dans {{ wait }} s</span>
    </p>
  </form>
</template>
