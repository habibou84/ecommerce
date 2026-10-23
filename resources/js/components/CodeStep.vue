<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import http, { errorMessage, fieldErrors } from '../lib/http'
import Alert from './Alert.vue'
import Field from './Field.vue'

/**
 * Preuve du numéro d'une vérification (création de boutique, invitation, mot de passe oublié, connexion) :
 *  - WhatsApp d'abord : la personne envoie depuis son téléphone un message déjà écrit au numéro du support ; la page
 *    interroge le serveur toutes les trois secondes et termine toute seule ;
 *  - SMS en dernier recours (seulement quand le serveur le propose) : code à 6 chiffres saisi ici.
 * Mot de passe oublié : une fois le numéro prouvé, le nouveau mot de passe est demandé ici (askPassword).
 */
const props = defineProps({
  verification: { type: Object, required: true }, // { token, phone, status, whatsapp, sms, resend_in }
  demoCode: { type: String, default: null },
  askPassword: { type: Boolean, default: false },
  submitLabel: { type: String, default: 'Valider' },
})
const emit = defineEmits(['done'])

const state = ref({ ...props.verification })
const smsMode = ref(!props.verification.whatsapp)
const code = ref('')
const password = ref('')
const errors = ref({})
const error = ref('')
const info = ref('')
const busy = ref(false)
const demo = ref(props.demoCode)
const wait = ref(props.verification.sms?.resend_in ?? props.verification.resend_in ?? 0)

const sms = computed(() => state.value.sms ?? { available: true, sent: !!demo.value || !state.value.whatsapp, resend_in: 0 })
const verified = computed(() => state.value.status === 'verified')
// Numéro prouvé par WhatsApp et nouveau mot de passe à choisir : plus de code à saisir
const passwordOnly = computed(() => props.askPassword && verified.value)

const ticker = setInterval(() => {
  if (wait.value > 0) wait.value--
}, 1000)
let poller = null

function apply(data) {
  if (data.verification) state.value = { ...state.value, ...data.verification }
  if (data.redirect !== undefined || data.status === 'completed') {
    stop()
    emit('done', data)
  } else if (['completed', 'expired'].includes(state.value.status)) {
    stop()
    error.value = state.value.status === 'expired' ? 'Cette demande a expiré. Recommencez.' : 'Cette demande a déjà été utilisée (depuis un autre onglet ou appareil). Recommencez.'
  }
}

// Le message WhatsApp arrive sur le serveur : la page interroge l'état régulièrement
async function poll() {
  if (document.hidden || busy.value || smsMode.value && !state.value.whatsapp || verified.value) return
  try {
    const { data } = await http.get(`/verifications/${state.value.token}`)
    apply(data)
  } catch (e) {
    if (e.response?.status === 422 || e.response?.status === 404) {
      stop()
      error.value = errorMessage(e)
    }
  }
}

function stop() {
  clearInterval(poller)
  document.removeEventListener('visibilitychange', poll)
}

async function submit() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    const { data } = await http.post(`/verifications/${state.value.token}/confirm`, {
      code: passwordOnly.value ? undefined : code.value,
      password: props.askPassword ? password.value : undefined,
    })
    stop()
    emit('done', data)
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!errors.value.code && !errors.value.password) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

// Premier code SMS (quand la personne choisit le SMS) ou nouveau code
async function sendSms() {
  error.value = ''
  info.value = ''
  busy.value = true
  try {
    const { data } = await http.post(`/verifications/${state.value.token}/resend`)
    smsMode.value = true
    if (data.verification) state.value = { ...state.value, ...data.verification }
    wait.value = data.resend_in ?? 60
    demo.value = data.demo_code ?? demo.value
    info.value = data.message
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

onMounted(() => {
  if (state.value.whatsapp) {
    poller = setInterval(poll, 3000)
    // Retour depuis WhatsApp : vérification immédiate
    document.addEventListener('visibilitychange', poll)
    poll()
  }
})
onBeforeUnmount(() => {
  clearInterval(ticker)
  stop()
})
</script>

<template>
  <div class="space-y-4">
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="info" tone="success">{{ info }}</Alert>

    <!-- Nouveau mot de passe, numéro déjà prouvé par WhatsApp -->
    <form v-if="passwordOnly" class="space-y-4" @submit.prevent="submit">
      <Alert tone="success">Numéro vérifié par WhatsApp. Choisissez votre nouveau mot de passe.</Alert>
      <Field id="new-password" label="Nouveau mot de passe" :error="errors.password" hint="8 caractères au moins.">
        <input id="new-password" v-model="password" type="password" class="input" autocomplete="new-password" minlength="8" required autofocus />
      </Field>
      <button class="btn-brand w-full" :disabled="busy">{{ busy ? 'Enregistrement…' : submitLabel }}</button>
    </form>

    <!-- WhatsApp : la personne envoie le message prérempli depuis son téléphone -->
    <div v-else-if="state.whatsapp && !smsMode" class="space-y-3">
      <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-700">
        <li>Appuyez sur le bouton : WhatsApp s'ouvre avec un message déjà écrit.</li>
        <li>Envoyez-le <strong>depuis le WhatsApp du {{ state.phone }}</strong>, sans le modifier.</li>
        <li>Revenez ici : la page continue toute seule.</li>
      </ol>
      <a :href="state.whatsapp.link" target="_blank" rel="noopener" class="flex w-full items-center justify-center gap-2 rounded-lg bg-[#25D366] py-3 font-semibold text-white hover:bg-[#1ebe5b]">
        <span aria-hidden="true">💬</span> Envoyer le message WhatsApp
      </a>
      <p class="flex items-center justify-center gap-2 text-sm text-slate-500" aria-live="polite">
        <span class="h-2 w-2 animate-pulse rounded-full bg-emerald-500" aria-hidden="true"></span> En attente de votre message…
      </p>
      <p class="text-center text-xs text-slate-500">
        Le bouton ne marche pas ? Envoyez « {{ state.whatsapp.text }} » au {{ state.whatsapp.number }} sur WhatsApp.
      </p>
      <button v-if="sms.available" type="button" class="mx-auto block text-sm text-slate-600 underline" :disabled="busy" @click="sendSms">
        Pas de WhatsApp sur ce numéro ? Recevoir un code par SMS
      </button>
      <p v-else class="text-center text-xs text-slate-500">Pas de WhatsApp sur ce numéro ? Utilisez un numéro qui a WhatsApp, ou contactez-nous.</p>
    </div>

    <!-- SMS : code secret saisi sur la page -->
    <form v-else-if="sms.available" class="space-y-4" @submit.prevent="submit">
      <p class="text-sm text-slate-600">
        <template v-if="sms.sent">Nous avons envoyé un code à 6 chiffres par SMS au <strong class="whitespace-nowrap">{{ state.phone }}</strong>.</template>
        <template v-else>Nous allons envoyer un code à 6 chiffres par SMS au <strong class="whitespace-nowrap">{{ state.phone }}</strong>.</template>
      </p>
      <Alert v-if="demo" tone="info">Mode démonstration (aucun SMS envoyé) : le code est <strong>{{ demo }}</strong>.</Alert>

      <button v-if="!sms.sent" type="button" class="btn-brand w-full" :disabled="busy" @click="sendSms">{{ busy ? 'Envoi…' : 'Recevoir le code par SMS' }}</button>
      <template v-else>
        <Field id="code" label="Code reçu par SMS" :error="errors.code">
          <input id="code" v-model="code" class="input text-center text-2xl tracking-[0.5em]" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus />
        </Field>

        <Field v-if="askPassword" id="new-password" label="Nouveau mot de passe" :error="errors.password" hint="8 caractères au moins.">
          <input id="new-password" v-model="password" type="password" class="input" autocomplete="new-password" minlength="8" required />
        </Field>

        <button class="btn-brand w-full" :disabled="busy || code.length < 6">{{ busy ? 'Vérification…' : submitLabel }}</button>

        <p class="text-center text-sm text-slate-600">
          <button v-if="wait === 0" type="button" class="font-medium text-emerald-700 hover:underline" :disabled="busy" @click="sendSms">Renvoyer un code</button>
          <span v-else>Nouveau code possible dans {{ wait }} s</span>
        </p>
      </template>
      <button v-if="state.whatsapp" type="button" class="mx-auto block text-sm text-slate-600 underline" @click="smsMode = false">Vérifier plutôt par WhatsApp</button>
    </form>

    <Alert v-else tone="warning">La vérification de ce numéro n'est pas disponible pour le moment. Contactez-nous.</Alert>
  </div>
</template>
