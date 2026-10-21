<script setup>
import { onMounted, reactive, ref } from 'vue'
import AuthShell from '../../components/AuthShell.vue'
import Alert from '../../components/Alert.vue'
import CodeStep from '../../components/CodeStep.vue'
import Field from '../../components/Field.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'

const props = defineProps({ token: { type: String, required: true } })

const invitation = ref(null)
const missing = ref(false)
const form = reactive({ name: '', password: '' })
const errors = ref({})
const error = ref('')
const busy = ref(false)
const pending = ref(null)

onMounted(async () => {
  try {
    const { data } = await http.get(`/invitations/${props.token}`)
    invitation.value = data.data
    form.name = data.data.name ?? ''
  } catch {
    missing.value = true
  }
})

async function submit() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    const payload = invitation.value.has_account ? { password: form.password } : form
    const { data, status } = await http.post(`/invitations/${props.token}/accept`, payload)
    if (status === 202) pending.value = data
    else window.location.assign(data.redirect)
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

function done(data) {
  window.location.assign(data.redirect)
}
</script>

<template>
  <AuthShell v-if="missing" title="Invitation expirée" subtitle="Ce lien n'est plus valable. Demandez une nouvelle invitation à l'espace marchand." />

  <AuthShell
    v-else-if="invitation"
    :title="`Rejoindre ${invitation.store}`"
    :subtitle="`${invitation.inviter ?? 'L\'espace marchand'} vous invite comme ${invitation.role}.`"
  >
    <CodeStep v-if="pending" :verification="pending.verification" :demo-code="pending.demo_code" submit-label="Rejoindre l'équipe" @done="done" />

    <form v-else class="space-y-4" @submit.prevent="submit">
      <Alert v-if="error">{{ error }}</Alert>
      <p class="text-sm text-slate-600">Numéro invité : <strong>{{ invitation.phone }}</strong></p>

      <template v-if="invitation.has_account">
        <p class="text-sm text-slate-600">Ce numéro a déjà un compte Jibiat Marchand : saisissez son mot de passe.</p>
        <Field id="password" label="Mot de passe" :error="errors.password">
          <input id="password" v-model="form.password" class="input" type="password" autocomplete="current-password" required autofocus />
        </Field>
      </template>
      <template v-else>
        <Field id="name" label="Votre nom" :error="errors.name">
          <input id="name" v-model="form.name" class="input" autocomplete="name" maxlength="80" required />
        </Field>
        <Field id="password" label="Choisissez un mot de passe" :error="errors.password" hint="8 caractères au moins.">
          <input id="password" v-model="form.password" class="input" type="password" autocomplete="new-password" minlength="8" required />
        </Field>
      </template>

      <button class="btn-brand w-full" :disabled="busy">{{ busy ? 'Un instant…' : 'Rejoindre l\'équipe' }}</button>
    </form>
  </AuthShell>
</template>
