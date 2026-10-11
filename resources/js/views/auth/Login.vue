<script setup>
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AuthShell from '../../components/AuthShell.vue'
import Alert from '../../components/Alert.vue'
import Field from '../../components/Field.vue'
import { boot } from '../../lib/boot'
import { errorMessage, fieldErrors } from '../../lib/http'
import { useAuthStore } from '../../stores/auth'

const props = defineProps({
  home: { type: String, default: '/gestion' },
  title: { type: String, default: 'Gestion de la boutique' },
})
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const form = reactive({ phone: '', password: '' })
const errors = ref({})
const error = ref('')
const busy = ref(false)

async function submit() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    await auth.login(form.phone, form.password)
    // Un livreur arrive sur son application, les autres sur la gestion (ou la page demandée)
    const home = auth.profile.role?.value === 'courier' ? '/livreur' : props.home
    const back = typeof route.query.retour === 'string' && route.query.retour.startsWith(home) ? route.query.retour : home
    router.replace(back)
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthShell :title="title" subtitle="Connectez-vous avec votre numéro de téléphone.">
    <form class="space-y-4" @submit.prevent="submit">
      <Alert v-if="error">{{ error }}</Alert>
      <Field id="phone" label="Numéro de téléphone" :error="errors.phone">
        <input id="phone" v-model="form.phone" class="input" type="tel" inputmode="tel" autocomplete="tel" required autofocus />
      </Field>
      <Field id="password" label="Mot de passe" :error="errors.password">
        <input id="password" v-model="form.password" class="input" type="password" autocomplete="current-password" required />
      </Field>
      <button class="btn-brand w-full" :disabled="busy">{{ busy ? 'Connexion…' : 'Se connecter' }}</button>
      <p class="text-center text-sm">
        <RouterLink to="/gestion/mot-de-passe-oublie" class="text-emerald-700 hover:underline">Mot de passe oublié ?</RouterLink>
      </p>
    </form>
    <template #footer>
      <a :href="boot.platformUrl" class="hover:underline">Jibiat Marchand</a>
    </template>
  </AuthShell>
</template>
