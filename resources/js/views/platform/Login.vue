<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import AuthShell from '../../components/AuthShell.vue'
import Alert from '../../components/Alert.vue'
import CodeStep from '../../components/CodeStep.vue'
import Field from '../../components/Field.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { forgetStore, inApp, rememberStore, rememberedStore } from '../../lib/app'

// Pour qui ne connaît plus l'adresse de sa boutique : connexion, puis choix de la boutique
const form = reactive({ phone: '', password: '' })
const errors = ref({})
const error = ref('')
const busy = ref(false)
const account = ref(null)
const route = useRoute()
// Connexion par WhatsApp, sans mot de passe
const whatsappMode = ref(false)
const pending = ref(null)

async function startWhatsApp() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    const { data } = await http.post('/account/whatsapp', { phone: form.phone })
    pending.value = data
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function whatsappDone() {
  pending.value = null
  try {
    const { data } = await http.get('/account/stores')
    account.value = data.data
    const open = account.value.stores.filter((s) => s.is_open)
    if (account.value.stores.length === 1 && open.length === 1) await openStore(open[0])
  } catch (e) {
    error.value = errorMessage(e)
  }
}

// Dans l'application : la dernière boutique se rouvre directement (« Changer de boutique » revient ici avec ?choisir)
onMounted(() => {
  if (!inApp) return
  if (route.query.choisir) return forgetStore()
  const last = rememberedStore()
  if (last?.url) window.location.replace(`${last.url.replace(/\/$/, '')}/gestion`)
})

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
    if (inApp) rememberStore(store)
    window.location.assign(data.redirect)
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <AuthShell :title="account ? `Bonjour ${account.name}` : pending ? 'Vérifiez votre numéro' : 'Se connecter'" :subtitle="account ? 'Choisissez votre espace marchand.' : pending ? '' : whatsappMode ? 'Sans mot de passe : un message WhatsApp à envoyer, et vous êtes connecté.' : 'Retrouvez vos espaces marchands Jibiat Marchand.'">
    <Alert v-if="error" class="mb-4">{{ error }}</Alert>

    <div v-if="account" class="space-y-2">
      <p v-if="!account.stores.length" class="text-sm text-slate-600">Ce compte ne fait partie d'aucun espace marchand.</p>
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

    <CodeStep v-else-if="pending" :verification="pending.verification" :demo-code="pending.demo_code" submit-label="Me connecter" @done="whatsappDone" />

    <!-- Connexion par WhatsApp : seulement le numéro -->
    <form v-else-if="whatsappMode" class="space-y-4" @submit.prevent="startWhatsApp">
      <Field id="phone" label="Numéro WhatsApp de votre compte" :error="errors.phone">
        <input id="phone" v-model="form.phone" class="input" type="tel" inputmode="tel" autocomplete="tel" required autofocus />
      </Field>
      <button class="w-full rounded-lg bg-[#25D366] py-2.5 font-semibold text-white hover:bg-[#1ebe5b] disabled:opacity-60" :disabled="busy">{{ busy ? 'Un instant…' : 'Continuer' }}</button>
      <p class="text-center text-sm"><button type="button" class="text-slate-600 underline" @click="whatsappMode = false">Me connecter avec mon mot de passe</button></p>
    </form>

    <form v-else class="space-y-4" @submit.prevent="submit">
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
        <RouterLink to="/mot-de-passe-oublie" class="text-emerald-700 hover:underline">Mot de passe oublié ?</RouterLink>
      </p>
    </form>

    <template #footer>
      Pas encore d'espace marchand ? <RouterLink to="/creer-ma-boutique" class="font-medium text-emerald-700 hover:underline">Créer mon espace marchand</RouterLink>
    </template>
  </AuthShell>
</template>
