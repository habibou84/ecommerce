<script setup>
import { reactive, ref } from 'vue'
import AuthShell from '../../components/AuthShell.vue'
import Alert from '../../components/Alert.vue'
import Field from '../../components/Field.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'

// Pour qui ne connaît plus l'adresse de sa boutique : connexion, puis choix de la boutique
const form = reactive({ phone: '', password: '' })
const errors = ref({})
const error = ref('')
const busy = ref(false)
const account = ref(null)

async function submit() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    const { data } = await http.post('/account/login', form)
    account.value = data.data
    const open = account.value.stores.filter((s) => s.is_open)
    if (account.value.stores.length === 1 && open.length === 1) await openStore(open[0])
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function openStore(store) {
  try {
    const { data } = await http.post(`/account/stores/${store.id}/open`)
    window.location.assign(data.redirect)
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <AuthShell :title="account ? `Bonjour ${account.name}` : 'Se connecter'" :subtitle="account ? 'Choisissez votre boutique.' : 'Retrouvez vos boutiques Jibiat Marchand.'">
    <Alert v-if="error" class="mb-4">{{ error }}</Alert>

    <div v-if="account" class="space-y-2">
      <p v-if="!account.stores.length" class="text-sm text-slate-600">Ce compte ne fait partie d'aucune boutique.</p>
      <button
        v-for="store in account.stores"
        :key="store.id"
        class="flex w-full items-center justify-between rounded-lg border border-slate-200 px-4 py-3 text-left hover:border-emerald-600 disabled:opacity-60"
        :disabled="!store.is_open"
        @click="openStore(store)"
      >
        <span>
          <span class="block font-medium">{{ store.name }}</span>
          <span class="block text-sm text-slate-500">{{ store.url.replace(/^https?:\/\//, '').replace(/\/$/, '') }}</span>
        </span>
        <span class="text-sm" :class="store.is_open ? 'text-emerald-700' : 'text-slate-500'">{{ store.is_open ? 'Ouvrir →' : store.status_label }}</span>
      </button>
    </div>

    <form v-else class="space-y-4" @submit.prevent="submit">
      <Field id="phone" label="Numéro de téléphone" :error="errors.phone">
        <input id="phone" v-model="form.phone" class="input" type="tel" inputmode="tel" autocomplete="tel" required autofocus />
      </Field>
      <Field id="password" label="Mot de passe" :error="errors.password">
        <input id="password" v-model="form.password" class="input" type="password" autocomplete="current-password" required />
      </Field>
      <button class="btn-brand w-full" :disabled="busy">{{ busy ? 'Connexion…' : 'Se connecter' }}</button>
      <p class="text-center text-sm">
        <RouterLink to="/mot-de-passe-oublie" class="text-emerald-700 hover:underline">Mot de passe oublié ?</RouterLink>
      </p>
    </form>

    <template #footer>
      Pas encore de boutique ? <RouterLink to="/creer-ma-boutique" class="font-medium text-emerald-700 hover:underline">Créer ma boutique</RouterLink>
    </template>
  </AuthShell>
</template>
