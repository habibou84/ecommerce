<script setup>
import { reactive, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import Field from '../../components/Field.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { useConsoleStore } from '../../stores/console'

// Mon compte : adresse qui reçoit le code de connexion, mot de passe ; tout changement demande le mot de passe actuel
const store = useConsoleStore()
const form = reactive({ email: store.admin?.email ?? '', password: '', password_confirmation: '', current_password: '' })
const errors = ref({})
const error = ref('')
const saved = ref(false)
const busy = ref(false)
const test = ref(null)

async function save() {
  busy.value = true
  errors.value = {}
  error.value = ''
  saved.value = false
  try {
    store.admin = (await http.put('/console/account', form)).data.data
    Object.assign(form, { password: '', password_confirmation: '', current_password: '' })
    saved.value = true
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function sendTest() {
  test.value = null
  try {
    test.value = (await http.post('/console/account/test-email')).data.data
  } catch (e) {
    test.value = { ok: false, detail: errorMessage(e) }
  }
}
</script>

<template>
  <div class="max-w-xl space-y-4">
    <h2 class="text-xl font-semibold">Mon compte</h2>
    <Alert v-if="error">{{ error }}</Alert>

    <form class="card space-y-4 p-4 text-sm" @submit.prevent="save">
      <div>
        <p class="font-semibold">Code de connexion par e-mail</p>
        <p class="text-slate-600">
          À chaque connexion, après le mot de passe, un code à 6 chiffres arrive à cette adresse. Sans adresse (ou si l'e-mail ne part pas),
          la connexion repasse par la preuve de votre numéro.
        </p>
      </div>
      <Field id="email" label="Adresse e-mail" :error="errors.email"><input id="email" v-model="form.email" type="email" class="input" autocomplete="email" /></Field>
      <div class="flex flex-wrap items-center gap-2">
        <button type="button" class="btn-ghost" :disabled="!store.admin?.email" @click="sendTest">Recevoir un code d'essai</button>
        <span v-if="test" :class="test.ok ? 'text-emerald-700' : 'text-red-700'">{{ test.ok ? '✓' : '✗' }} {{ test.detail }}</span>
      </div>

      <div class="border-t border-slate-100 pt-4">
        <p class="font-semibold">Changer le mot de passe <span class="font-normal text-slate-500">(facultatif, 10 caractères au moins)</span></p>
      </div>
      <div class="grid gap-3 sm:grid-cols-2">
        <Field id="password" label="Nouveau mot de passe" :error="errors.password"><input id="password" v-model="form.password" type="password" class="input" autocomplete="new-password" /></Field>
        <Field id="password_confirmation" label="Le retaper"><input id="password_confirmation" v-model="form.password_confirmation" type="password" class="input" autocomplete="new-password" /></Field>
      </div>

      <div class="border-t border-slate-100 pt-4">
        <Field id="current_password" label="Mot de passe actuel (pour enregistrer)" :error="errors.current_password">
          <input id="current_password" v-model="form.current_password" type="password" class="input" autocomplete="current-password" required />
        </Field>
      </div>
      <div class="flex items-center gap-2"><button class="btn-brand" :disabled="busy">Enregistrer</button><span v-if="saved" class="text-emerald-700">Enregistré ✓</span></div>
    </form>
  </div>
</template>
