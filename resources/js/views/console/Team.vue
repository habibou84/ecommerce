<script setup>
import { onMounted, reactive, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import Field from '../../components/Field.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { formatDateTime } from '../../lib/format'

// Équipe de la console et mes sessions. Ajouter ou retirer un administrateur demande votre mot de passe ; toute
// l'équipe est prévenue par e-mail et l'action est inscrite au journal.
const admins = ref(null)
const sessions = ref(null)
const error = ref('')
const errors = ref({})
const adding = ref(false)
const form = reactive({ name: '', phone: '', email: '', current_password: '' })
const revoking = ref(null) // administrateur à retirer
const revokePassword = ref('')
const added = ref('')

async function load() {
  try {
    admins.value = (await http.get('/console/team')).data.data
    sessions.value = (await http.get('/console/sessions')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function add() {
  errors.value = {}
  error.value = ''
  try {
    admins.value = (await http.post('/console/team', form)).data.data
    added.value = `${form.name} est ajouté. Pour son premier accès : console › « Mot de passe oublié ? », avec son numéro, puis le code reçu par e-mail.`
    Object.assign(form, { name: '', phone: '', email: '', current_password: '' })
    adding.value = false
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  }
}

async function revoke() {
  errors.value = {}
  error.value = ''
  try {
    admins.value = (await http.post(`/console/team/${revoking.value.id}/revoke`, { current_password: revokePassword.value })).data.data
    revoking.value = null
    revokePassword.value = ''
  } catch (e) {
    errors.value = fieldErrors(e)
    error.value = errors.value.admin?.[0] ?? (Object.keys(errors.value).length ? '' : errorMessage(e))
  }
}

async function close(id) {
  sessions.value = (await http.delete(`/console/sessions/${id}`)).data.data
}
</script>

<template>
  <div class="max-w-3xl space-y-4">
    <h2 class="text-xl font-semibold">Équipe de la console</h2>
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="added" tone="success">{{ added }}</Alert>

    <section class="card divide-y divide-slate-100 text-sm">
      <div v-for="a in admins ?? []" :key="a.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 p-4">
        <div class="min-w-0 flex-1">
          <p class="font-semibold">{{ a.name }} <span v-if="a.me" class="font-normal text-slate-500">(vous)</span></p>
          <p class="text-slate-600">{{ a.phone }} · {{ a.email ?? 'pas d\'e-mail' }}</p>
          <p class="text-xs text-slate-500">{{ a.last_login_at ? `Dernière connexion le ${formatDateTime(a.last_login_at)}` : 'Jamais connecté' }}</p>
        </div>
        <button v-if="!a.me" class="text-red-700 underline" @click="revoking = a; revokePassword = ''">Retirer l'accès</button>
        <div v-if="revoking?.id === a.id" class="flex w-full flex-wrap items-end gap-2 rounded-lg bg-red-50 p-3">
          <Field id="revoke-password" :label="`Votre mot de passe pour retirer ${a.name}`" :error="errors.current_password" class="min-w-0 flex-1">
            <input id="revoke-password" v-model="revokePassword" type="password" class="input" autocomplete="current-password" />
          </Field>
          <button class="btn-danger" :disabled="!revokePassword" @click="revoke">Retirer</button>
          <button class="btn-ghost" @click="revoking = null">Annuler</button>
        </div>
      </div>
    </section>

    <button v-if="!adding" class="btn-secondary" @click="adding = true">Ajouter un administrateur</button>
    <form v-else class="card space-y-3 p-4 text-sm" @submit.prevent="add">
      <p class="font-semibold">Nouvel administrateur</p>
      <p class="text-slate-600">
        Il aura accès à toute la console. Il choisira lui-même son mot de passe (« Mot de passe oublié ? » sur la page de connexion) et recevra le code de connexion à son adresse e-mail.
        Un numéro qui a déjà un compte marchand n'est pas accepté : la console a ses propres comptes.
      </p>
      <div class="grid gap-3 sm:grid-cols-3">
        <Field id="admin-name" label="Nom" :error="errors.name"><input id="admin-name" v-model="form.name" class="input" required /></Field>
        <Field id="admin-phone" label="Numéro" :error="errors.phone"><input id="admin-phone" v-model="form.phone" type="tel" class="input" required /></Field>
        <Field id="admin-email" label="E-mail" :error="errors.email"><input id="admin-email" v-model="form.email" type="email" class="input" required /></Field>
      </div>
      <Field id="admin-password" label="Votre mot de passe (pour confirmer)" :error="errors.current_password"><input id="admin-password" v-model="form.current_password" type="password" class="input max-w-xs" autocomplete="current-password" required /></Field>
      <div class="flex gap-2"><button class="btn-brand">Ajouter</button><button type="button" class="btn-ghost" @click="adding = false">Annuler</button></div>
    </form>

    <h3 class="pt-2 text-lg font-semibold">Mes sessions</h3>
    <p class="-mt-2 text-sm text-slate-600">Les appareils connectés à votre compte. Un appareil inconnu ? Fermez-le et changez votre mot de passe.</p>
    <section class="card divide-y divide-slate-100 text-sm">
      <div v-for="s in sessions ?? []" :key="s.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 p-4">
        <div class="min-w-0 flex-1">
          <p class="font-semibold">{{ s.current ? 'Cet appareil' : 'Autre appareil' }} · {{ s.ip }}</p>
          <p class="truncate text-xs text-slate-500">{{ s.device }}</p>
          <p class="text-xs text-slate-500">Dernière activité le {{ formatDateTime(s.last_activity) }}</p>
        </div>
        <button v-if="!s.current" class="text-red-700 underline" @click="close(s.id)">Fermer</button>
      </div>
    </section>
    <button v-if="sessions?.some((s) => !s.current)" class="btn-ghost" @click="close('others')">Fermer toutes mes autres sessions</button>
  </div>
</template>
