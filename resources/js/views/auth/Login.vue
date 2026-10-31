<script setup>
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AuthShell from '../../components/AuthShell.vue'
import Alert from '../../components/Alert.vue'
import CodeStep from '../../components/CodeStep.vue'
import Field from '../../components/Field.vue'
import { inApp } from '../../lib/app'
import { boot } from '../../lib/boot'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { useAuthStore } from '../../stores/auth'

const props = defineProps({
  home: { type: String, default: '/gestion' },
  title: { type: String, default: 'Votre espace marchand' },
})
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const form = reactive({ phone: '', password: '' })
const errors = ref({})
const error = ref('')
const busy = ref(false)
// Connexion par WhatsApp, sans mot de passe : numéro, message prérempli, session ouverte par la page
const whatsappMode = ref(false)
const pending = ref(null)

function landing() {
  const home = auth.profile?.role?.value === 'courier' ? '/livreur' : props.home
  const back = typeof route.query.retour === 'string' && route.query.retour.startsWith(home) ? route.query.retour : home
  return back
}

async function startWhatsApp() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    const { data } = await http.post('/auth/whatsapp', { phone: form.phone })
    pending.value = data
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function whatsappDone(data) {
  // Session ouverte par le serveur : profil rechargé, puis chacun arrive dans son espace
  await auth.fetch()
  const back = typeof route.query.retour === 'string' && route.query.retour.startsWith(data.redirect || props.home) ? route.query.retour : (data.redirect || landing())
  if (back.startsWith('/livreur') !== route.path.startsWith('/livreur')) window.location.assign(back)
  else router.replace(back)
}

async function submit() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    await auth.login(form.phone, form.password)
    // Un livreur arrive sur son application, les autres sur la gestion (ou la page demandée)
    router.replace(landing())
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

// Lien d'accès du livreur qui n'a pas fonctionné (voir CourierAccess) : on explique pourquoi
const LINK_PROBLEMS = {
  invalide: 'Ce lien ne fonctionne plus : demandez-en un nouveau à la boutique.',
  utilise: 'Ce lien a déjà été ouvert. Sur le téléphone où vous l\'avez ouvert, vous êtes connecté ; sinon, demandez un nouveau lien à la boutique.',
  expire: 'Ce lien a expiré : demandez-en un nouveau à la boutique.',
  retire: 'Votre accès livreur a été retiré par la boutique.',
  compte: 'Ce numéro a déjà un compte Jibiat Marchand : connectez-vous avec votre mot de passe ou par WhatsApp.',
}
const linkProblem = computed(() => LINK_PROBLEMS[route.query.lien] ?? '')
</script>

<template>
  <AuthShell :title="pending ? 'Vérifiez votre numéro' : title" :subtitle="pending ? '' : whatsappMode ? 'Sans mot de passe : un message WhatsApp à envoyer, et vous êtes connecté.' : 'Connectez-vous avec votre numéro de téléphone.'">
    <Alert v-if="linkProblem && !pending" tone="warning" class="mb-4">{{ linkProblem }}</Alert>
    <CodeStep v-if="pending" :verification="pending.verification" :demo-code="pending.demo_code" submit-label="Me connecter" @done="whatsappDone" />

    <!-- Connexion par WhatsApp : seulement le numéro -->
    <form v-else-if="whatsappMode" class="space-y-4" @submit.prevent="startWhatsApp">
      <Alert v-if="error">{{ error }}</Alert>
      <Field id="phone" label="Numéro WhatsApp de votre compte" :error="errors.phone">
        <input id="phone" v-model="form.phone" class="input" type="tel" inputmode="tel" autocomplete="tel" required autofocus />
      </Field>
      <button class="w-full rounded-lg bg-[#25D366] py-2.5 font-semibold text-white hover:bg-[#1ebe5b] disabled:opacity-60" :disabled="busy">{{ busy ? 'Un instant…' : 'Continuer' }}</button>
      <p class="text-center text-sm">
        <button type="button" class="text-slate-600 underline" @click="whatsappMode = false">Me connecter avec mon mot de passe</button>
      </p>
    </form>

    <form v-else class="space-y-4" @submit.prevent="submit">
      <Alert v-if="error">{{ error }}</Alert>
      <Field id="phone" label="Numéro de téléphone" :error="errors.phone">
        <input id="phone" v-model="form.phone" class="input" type="tel" inputmode="tel" autocomplete="tel" required autofocus />
      </Field>
      <Field id="password" label="Mot de passe" :error="errors.password">
        <input id="password" v-model="form.password" class="input" type="password" autocomplete="current-password" required />
      </Field>
      <button class="btn-brand w-full" :disabled="busy">{{ busy ? 'Connexion…' : 'Se connecter' }}</button>
      <p class="flex items-center gap-3 text-xs text-slate-400"><span class="h-px flex-1 bg-slate-200"></span>ou<span class="h-px flex-1 bg-slate-200"></span></p>
      <button type="button" class="w-full rounded-lg border-2 border-[#25D366] py-2.5 font-semibold text-emerald-700 hover:bg-emerald-50" @click="whatsappMode = true; error = ''; errors = {}">
        💬 Me connecter par WhatsApp
      </button>
      <p class="text-center text-sm">
        <RouterLink to="/gestion/mot-de-passe-oublie" class="text-emerald-700 hover:underline">Mot de passe oublié ?</RouterLink>
      </p>
    </form>
    <template #footer>
      <a v-if="inApp" :href="`${boot.platformUrl.replace(/\/$/, '')}/connexion?app=1&choisir=1`" class="font-medium text-emerald-700 hover:underline">Changer d'espace marchand</a>
      <a v-else :href="boot.platformUrl" class="hover:underline">Jibiat Marchand</a>
    </template>
  </AuthShell>
</template>
