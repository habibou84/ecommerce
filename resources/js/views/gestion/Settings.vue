<script setup>
import { computed, reactive, ref, watch } from 'vue'
import Alert from '../../components/Alert.vue'
import Field from '../../components/Field.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { boot } from '../../lib/boot'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const canEdit = computed(() => auth.can('store.manage'))

const form = reactive({
  name: auth.store?.name ?? '',
  tagline: auth.store?.tagline ?? '',
  whatsapp_phone: auth.store?.whatsapp_phone ?? '',
  phone: auth.store?.phone ?? '',
  email: auth.store?.email ?? '',
  address: auth.store?.address ?? '',
})
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
    const { data } = await http.patch('/store', form)
    auth.setStore(data.data)
    saved.value = true
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    saving.value = false
  }
}

// Adresse de la boutique
// Boutique hébergée : seul le nom de sa page change ({adresse}/{nom})
const hosted = computed(() => !!auth.store?.host_url)
const currentSlug = computed(() => (hosted.value ? auth.store?.path : auth.store?.slug) ?? '')
const slug = ref(currentSlug.value)
const slugError = ref('')
const slugBusy = ref(false)
watch(slug, (v) => (slug.value = v.toLowerCase()))

async function changeSlug() {
  const target = hosted.value ? `${auth.store.host_url}/${slug.value}` : `${slug.value}.${boot.domain}`
  const warning = hosted.value ? 'Les anciens liens vers cette page ne fonctionneront plus.' : 'L\'ancienne adresse redirigera vers la nouvelle pendant 90 jours.'
  if (!confirm(`Votre boutique passera à l'adresse ${target}. ${warning} Continuer ?`)) return
  slugBusy.value = true
  slugError.value = ''
  try {
    const { data } = await http.put('/store/slug', { slug: slug.value })
    // La session est liée à l'adresse : on rejoint la nouvelle avec un lien de connexion (boutique hébergée : même adresse)
    if (data.redirect) window.location.assign(data.redirect)
    else {
      auth.setStore(data.data)
      slugBusy.value = false
    }
  } catch (e) {
    slugError.value = fieldErrors(e).slug ?? errorMessage(e)
    slugBusy.value = false
  }
}
</script>

<template>
  <div class="space-y-6">
    <form class="card space-y-4 p-4 sm:p-6" @submit.prevent="save">
      <h2 class="font-semibold">Informations de la boutique</h2>
      <Alert v-if="error">{{ error }}</Alert>
      <Alert v-if="saved" tone="success">Modifications enregistrées.</Alert>
      <Alert v-if="!canEdit" tone="info">Seuls le propriétaire et le gérant peuvent modifier ces informations.</Alert>

      <fieldset class="grid gap-4 sm:grid-cols-2" :disabled="!canEdit">
        <Field id="name" label="Nom" :error="errors.name">
          <input id="name" v-model="form.name" class="input" maxlength="80" required />
        </Field>
        <Field id="tagline" label="Slogan" :error="errors.tagline" hint="Affiché sur votre site et dans les aperçus WhatsApp.">
          <input id="tagline" v-model="form.tagline" class="input" maxlength="160" />
        </Field>
        <Field id="whatsapp_phone" label="WhatsApp des clients" :error="errors.whatsapp_phone" hint="Bouton « Commander sur WhatsApp » du site.">
          <input id="whatsapp_phone" v-model="form.whatsapp_phone" class="input" type="tel" inputmode="tel" />
        </Field>
        <Field id="phone" label="Téléphone" :error="errors.phone">
          <input id="phone" v-model="form.phone" class="input" type="tel" inputmode="tel" />
        </Field>
        <Field id="email" label="E-mail" :error="errors.email">
          <input id="email" v-model="form.email" class="input" type="email" />
        </Field>
        <Field id="address" label="Adresse" :error="errors.address">
          <input id="address" v-model="form.address" class="input" maxlength="500" />
        </Field>
      </fieldset>
      <button v-if="canEdit" class="btn-brand" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
    </form>


    <form v-if="canEdit" class="card space-y-4 p-4 sm:p-6" @submit.prevent="changeSlug">
      <div>
        <h2 class="font-semibold">Adresse de la boutique</h2>
        <p v-if="hosted" class="mt-1 text-sm text-slate-600">Cette boutique est une page de votre adresse principale : vous choisissez le nom de la page.</p>
        <p v-else class="mt-1 text-sm text-slate-600">L'ancienne adresse redirige vers la nouvelle pendant 90 jours.</p>
      </div>
      <Field id="slug" :label="hosted ? 'Nom de la page' : 'Adresse'" :error="slugError">
        <div class="flex items-stretch overflow-hidden rounded-lg border border-slate-300 focus-within:border-emerald-600">
          <span v-if="hosted" class="flex max-w-[55%] items-center truncate bg-slate-50 px-3 text-sm text-slate-500">{{ auth.store.host_url.replace(/^https?:\/\//, '') }}/</span>
          <input id="slug" v-model="slug" class="min-w-0 flex-1 px-3 py-2.5 text-base outline-none sm:text-sm" :maxlength="hosted ? 30 : 40" autocapitalize="off" spellcheck="false" />
          <span v-if="!hosted" class="flex items-center bg-slate-50 px-3 text-sm text-slate-500">.{{ boot.domain }}</span>
        </div>
      </Field>
      <button class="btn-secondary" :disabled="slugBusy || slug === currentSlug">Changer l'adresse</button>
    </form>

    <section v-if="auth.store?.subscription" class="card p-4 sm:p-6">
      <h2 class="font-semibold">Abonnement</h2>
      <p class="mt-1 text-sm text-slate-600">
        Offre {{ auth.store.subscription.plan }}
        <template v-if="auth.store.subscription.covered_by"> (abonnement de {{ auth.store.subscription.covered_by }})</template>
        <template v-else-if="auth.store.subscription.status === 'trialing'"> (essai gratuit)</template>.
        <RouterLink v-if="auth.can('billing.manage')" to="/gestion/abonnement" class="text-emerald-700 underline">Gérer l'abonnement</RouterLink>
      </p>
    </section>
  </div>
</template>
