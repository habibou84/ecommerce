<script setup>
import PasswordInput from '../../components/PasswordInput.vue'
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AuthShell from '../../components/AuthShell.vue'
import Alert from '../../components/Alert.vue'
import Field from '../../components/Field.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { useConsoleStore } from '../../stores/console'

// Validation en deux étapes : QR code à scanner dans l'application d'authentification, premier code, puis codes de
// secours à noter. Obligatoire au premier accès ; « ?changer=1 » pour passer sur un nouveau téléphone (mot de passe).
const store = useConsoleStore()
const route = useRoute()
const router = useRouter()
const changing = computed(() => store.admin?.two_factor && route.query.changer)
const password = ref('')
const draft = ref(null) // { secret, uri, qr }
const code = ref('')
const codes = ref(null)
const noted = ref(false)
const errors = ref({})
const error = ref('')
const busy = ref(false)
const copied = ref('')

async function start() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    draft.value = (await http.post('/console/2fa/setup', changing.value ? { current_password: password.value } : {})).data.data
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
onMounted(() => {
  if (!changing.value) start()
})

async function confirm() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    codes.value = (await http.post('/console/2fa/confirm', { code: code.value })).data.data.recovery_codes
    store.admin = { ...store.admin, two_factor: true }
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function copy(text, what) {
  try {
    await navigator.clipboard.writeText(text)
    copied.value = what
    setTimeout(() => (copied.value = ''), 2000)
  } catch {
    // Presse-papiers indisponible : le texte reste affiché pour être recopié
  }
}

function download() {
  const text = `Console Jibiat Marchand — codes de secours (${store.admin?.phone})\nChaque code ne sert qu'une fois.\n\n${codes.value.join('\n')}\n`
  const link = Object.assign(document.createElement('a'), { href: URL.createObjectURL(new Blob([text], { type: 'text/plain' })), download: 'codes-de-secours-console.txt' })
  link.click()
  URL.revokeObjectURL(link.href)
}

const spacedSecret = computed(() => draft.value?.secret.match(/.{1,4}/g).join(' '))
</script>

<template>
  <AuthShell title="Validation en deux étapes" :subtitle="codes ? 'Notez vos codes de secours.' : changing ? 'Nouveau téléphone' : 'Obligatoire pour ouvrir la console.'">
    <Alert v-if="error" class="mb-4">{{ error }}</Alert>

    <div v-if="codes" class="space-y-4 text-sm">
      <Alert tone="success">✓ C'est activé. À chaque connexion : mot de passe, puis le code de l'application.</Alert>
      <p class="text-slate-700">Téléphone perdu ou changé ? Un de ces codes remplace celui de l'application, <b>une seule fois chacun</b>. Gardez-les hors du téléphone (papier, coffre-fort de mots de passe).</p>
      <ul class="grid grid-cols-2 gap-2 rounded-xl bg-slate-50 p-3 text-center font-mono text-base font-semibold tracking-wide">
        <li v-for="c in codes" :key="c">{{ c }}</li>
      </ul>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-ghost" @click="copy(codes.join('\n'), 'codes')">{{ copied === 'codes' ? '✓ Copiés' : 'Copier' }}</button>
        <button type="button" class="btn-ghost" @click="download">Télécharger (.txt)</button>
      </div>
      <label class="flex items-center gap-2 font-semibold"><input v-model="noted" type="checkbox" class="h-5 w-5" /> J'ai noté mes codes de secours</label>
      <button class="btn-brand w-full" :disabled="!noted" @click="router.push(route.query.changer ? '/console/compte' : '/console')">Continuer</button>
    </div>

    <form v-else-if="changing && !draft" class="space-y-4" @submit.prevent="start">
      <p class="text-sm text-slate-700">L'ancien téléphone restera valable jusqu'à ce que le nouveau soit confirmé.</p>
      <Field id="current_password" label="Mot de passe actuel" :error="errors.current_password"><PasswordInput id="current_password" v-model="password" class="input" autocomplete="current-password" required autofocus /></Field>
      <button class="btn-brand w-full" :disabled="busy || !password">Afficher le QR code</button>
      <RouterLink to="/console/compte" class="block text-center text-sm text-slate-600 hover:underline">Annuler</RouterLink>
    </form>

    <form v-else-if="draft" class="space-y-4 text-sm" @submit.prevent="confirm">
      <ol class="list-decimal space-y-1 pl-5 text-slate-700">
        <li>Installez <b>Google Authenticator</b> ou <b>Microsoft Authenticator</b> sur votre téléphone.</li>
        <li>Dans l'application, touchez <b>＋</b> puis « Scanner un QR code ».</li>
        <li>Tapez le code à 6 chiffres qu'elle affiche.</li>
      </ol>
      <img :src="draft.qr" alt="QR code à scanner avec l'application d'authentification" class="mx-auto h-52 w-52 rounded-lg border border-slate-200" />
      <details class="rounded-lg bg-slate-50 p-3">
        <summary class="cursor-pointer font-semibold">Scanner impossible (console ouverte sur le téléphone) ?</summary>
        <p class="mt-2 text-slate-700">Dans l'application, choisissez « Saisir une clé » et recopiez :</p>
        <p class="mt-1 break-all font-mono text-base font-semibold">{{ spacedSecret }}</p>
        <div class="mt-2 flex flex-wrap gap-2">
          <button type="button" class="btn-ghost" @click="copy(draft.secret, 'secret')">{{ copied === 'secret' ? '✓ Copiée' : 'Copier la clé' }}</button>
          <a :href="draft.uri" class="btn-ghost">Ouvrir dans l'application</a>
        </div>
      </details>
      <Field id="code" label="Code affiché par l'application" :error="errors.code">
        <input id="code" v-model="code" class="input text-center text-2xl font-bold tracking-[0.4em]" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required />
      </Field>
      <button class="btn-brand w-full" :disabled="busy || code.length < 6">{{ busy ? '…' : 'Activer' }}</button>
    </form>

    <p v-else class="text-center text-slate-500">…</p>
    <button v-if="!codes && !changing" type="button" class="mt-4 block w-full text-center text-sm text-slate-600 hover:underline" @click="store.logout().then(() => router.push('/console/connexion'))">Se déconnecter</button>
  </AuthShell>
</template>
