<script setup>
import { reactive, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import Field from '../../components/Field.vue'
import Modal from '../../components/Modal.vue'
import { boot } from '../../lib/boot'
import { unregisterDevice } from '../../lib/push'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()

// Nom et adresse email (rapport du jour par email)
const form = reactive({ name: auth.user?.name ?? '', email: auth.user?.email ?? '' })
const errors = ref({})
const error = ref('')
const saved = ref(false)
const saving = ref(false)

async function save() {
  saving.value = true
  errors.value = {}
  error.value = ''
  saved.value = false
  try {
    const { data } = await http.put('/auth/account', form)
    auth.profile = data.data
    form.email = data.data.user.email ?? ''
    saved.value = true
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    saving.value = false
  }
}

// Mot de passe
const pwd = reactive({ current_password: '', password: '', password_confirmation: '' })
const pwdErrors = ref({})
const pwdError = ref('')
const pwdSaved = ref(false)
const pwdSaving = ref(false)

async function changePassword() {
  pwdSaving.value = true
  pwdErrors.value = {}
  pwdError.value = ''
  pwdSaved.value = false
  try {
    await http.put('/auth/password', pwd)
    Object.assign(pwd, { current_password: '', password: '', password_confirmation: '' })
    pwdSaved.value = true
  } catch (e) {
    pwdErrors.value = fieldErrors(e)
    if (!Object.keys(pwdErrors.value).length) pwdError.value = errorMessage(e)
  } finally {
    pwdSaving.value = false
  }
}

// Suppression du compte : mot de passe demandé ; le propriétaire ferme d'abord sa boutique
const removing = ref(false)
const removePassword = ref('')
const removeError = ref('')
const removeBusy = ref(false)

async function removeAccount() {
  removeBusy.value = true
  removeError.value = ''
  try {
    await unregisterDevice()
    await http.delete('/auth/account', { data: { password: removePassword.value } })
    window.location.href = `${boot.platformUrl.replace(/\/$/, '')}/connexion`
  } catch (e) {
    removeError.value = errorMessage(e)
  } finally {
    removeBusy.value = false
  }
}
</script>

<template>
  <div class="space-y-6">
    <form class="card space-y-4 p-4 sm:p-6" @submit.prevent="save">
      <h2 class="font-semibold">Mes informations</h2>
      <Alert v-if="error">{{ error }}</Alert>
      <Alert v-if="saved" tone="success">Modifications enregistrées.</Alert>

      <div class="grid gap-4 sm:grid-cols-2">
        <Field id="account-name" label="Nom" :error="errors.name">
          <input id="account-name" v-model="form.name" class="input" maxlength="100" required />
        </Field>
        <Field id="account-phone" label="Téléphone" hint="Sert à vous connecter ; il ne peut pas être modifié ici.">
          <input id="account-phone" :value="auth.user?.phone_display" class="input" disabled />
        </Field>
        <Field id="account-email" label="Adresse email" :error="errors.email" hint="Pour recevoir le rapport du jour par email (Ventes › Statistiques).">
          <input id="account-email" v-model="form.email" class="input" type="email" maxlength="255" placeholder="vous@exemple.com" />
        </Field>
      </div>
      <button class="btn-brand" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
    </form>

    <form class="card space-y-4 p-4 sm:p-6" @submit.prevent="changePassword">
      <h2 class="font-semibold">Mot de passe</h2>
      <Alert v-if="pwdError">{{ pwdError }}</Alert>
      <Alert v-if="pwdSaved" tone="success">Mot de passe modifié.</Alert>

      <div class="grid gap-4 sm:grid-cols-3">
        <Field id="current-password" label="Mot de passe actuel" :error="pwdErrors.current_password">
          <input id="current-password" v-model="pwd.current_password" class="input" type="password" autocomplete="current-password" required />
        </Field>
        <Field id="new-password" label="Nouveau mot de passe" :error="pwdErrors.password" hint="8 caractères au moins.">
          <input id="new-password" v-model="pwd.password" class="input" type="password" autocomplete="new-password" minlength="8" required />
        </Field>
        <Field id="new-password-confirmation" label="Confirmer" :error="pwdErrors.password_confirmation">
          <input id="new-password-confirmation" v-model="pwd.password_confirmation" class="input" type="password" autocomplete="new-password" required />
        </Field>
      </div>
      <button class="btn-brand" :disabled="pwdSaving">{{ pwdSaving ? 'Modification…' : 'Changer le mot de passe' }}</button>
    </form>

    <section class="card space-y-3 p-4 sm:p-6">
      <h2 class="font-semibold">Supprimer mon compte</h2>
      <p class="text-sm text-slate-600">
        Votre nom, votre téléphone et votre email sont effacés et vous perdez l'accès à toutes vos boutiques. Les commandes que vous avez enregistrées restent dans les boutiques. Si vous êtes propriétaire, fermez d'abord la boutique (Réglages › Données et fermeture).
      </p>
      <button type="button" class="btn-secondary text-red-700" @click="removing = true">Supprimer mon compte</button>
    </section>

    <Modal v-if="removing" title="Supprimer votre compte ?" @close="removing = false">
      <form class="space-y-4 text-sm" @submit.prevent="removeAccount">
        <p>C'est définitif. Pour confirmer, saisissez votre mot de passe.</p>
        <Alert v-if="removeError">{{ removeError }}</Alert>
        <Field id="remove-password" label="Mot de passe">
          <input id="remove-password" v-model="removePassword" class="input" type="password" autocomplete="current-password" required />
        </Field>
        <div class="grid grid-cols-2 gap-2">
          <button type="button" class="btn-secondary" @click="removing = false">Annuler</button>
          <button class="btn-danger" :disabled="removeBusy">{{ removeBusy ? 'Suppression…' : 'Supprimer' }}</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
