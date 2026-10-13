<script setup>
import { computed, onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import OnlineStoreTabs from '../../../components/OnlineStoreTabs.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { useAuthStore } from '../../../stores/auth'

const auth = useAuthStore()
const state = ref(null)
const settings = ref({})
const error = ref('')
const errors = ref({})
const saved = ref(false)
const saving = ref(false)
const busy = ref('')

function apply(data) {
  state.value = data
  settings.value = { ...data.theme.settings }
  if (auth.store) auth.store.storefront_enabled = data.enabled
}

onMounted(async () => {
  try {
    apply((await http.get('/online-store')).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  }
})

const textFields = computed(() => state.value?.schema.filter((f) => !['color', 'image', 'boolean'].includes(f.type)) ?? [])
const colorFields = computed(() => state.value?.schema.filter((f) => f.type === 'color') ?? [])
const toggles = computed(() => state.value?.schema.filter((f) => f.type === 'boolean') ?? [])
const checklist = computed(() => {
  const c = state.value?.checklist
  if (!c) return []
  return [
    { done: c.products > 0, text: c.products ? `${c.products} produit(s) en vente en ligne` : 'Aucun produit actif et visible en ligne', to: '/gestion/catalogue' },
    { done: c.zones > 0, text: c.zones ? 'Zones et frais de livraison définis' : 'Aucune zone de livraison active', to: '/gestion/livraisons/zones' },
    { done: c.whatsapp, text: c.whatsapp ? 'Numéro WhatsApp renseigné' : 'Ajoutez votre numéro WhatsApp (bouton « Commander sur WhatsApp »)', to: '/gestion/parametres' },
    { done: c.logo, text: c.logo ? 'Logo ajouté' : 'Ajoutez votre logo (ci-dessous)' },
  ]
})

async function run(key, request) {
  busy.value = key
  error.value = ''
  try {
    apply((await request()).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = ''
  }
}

const toggleOpen = () => run('open', () => http.patch('/online-store', { enabled: !state.value.enabled }))
const activate = (code) => run('theme', () => http.post('/online-store/theme/activate', { code }))

function upload(kind, event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  const body = new FormData()
  body.append('image', file)
  run(kind, () => http.post(kind === 'logo' ? '/online-store/logo' : '/online-store/theme/hero-image', body))
}
const removeImage = (kind) => run(kind, () => http.delete(kind === 'logo' ? '/online-store/logo' : '/online-store/theme/hero-image'))

async function preview() {
  const tab = window.open('', '_blank')
  try {
    const { data } = await http.post('/online-store/preview')
    if (tab) tab.location.href = data.data.url
    else window.location.href = data.data.url
  } catch (e) {
    tab?.close()
    error.value = errorMessage(e)
  }
}

async function save() {
  saving.value = true
  errors.value = {}
  saved.value = false
  try {
    apply((await http.put('/online-store/theme', { settings: settings.value })).data.data)
    saved.value = true
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <OnlineStoreTabs />
    <Alert v-if="error">{{ error }}</Alert>

    <template v-if="state">
      <section class="card flex flex-wrap items-center gap-4 p-4">
        <div class="min-w-0 flex-1">
          <p class="font-medium">
            Votre boutique est
            <span :class="state.enabled ? 'text-emerald-700' : 'text-amber-700'">{{ state.enabled ? 'ouverte' : 'fermée au public' }}</span>
          </p>
          <a :href="state.url" target="_blank" class="text-sm text-slate-500 underline">{{ state.url.replace(/^https?:\/\//, '') }}</a>
        </div>
        <button v-if="!state.enabled" type="button" class="btn-secondary" @click="preview">Aperçu</button>
        <a v-else :href="state.url" target="_blank" class="btn-secondary">Voir la boutique</a>
        <button type="button" :class="state.enabled ? 'btn-secondary' : 'btn-brand'" :disabled="busy === 'open'" @click="toggleOpen">
          {{ state.enabled ? 'Fermer la boutique' : 'Ouvrir la boutique' }}
        </button>
        <ul class="w-full space-y-1 border-t border-slate-100 pt-3 text-sm">
          <li v-for="item in checklist" :key="item.text" class="flex items-center gap-2">
            <span :class="item.done ? 'text-emerald-700' : 'text-amber-600'">{{ item.done ? '✓' : '•' }}</span>
            <RouterLink v-if="item.to && !item.done" :to="item.to" class="underline">{{ item.text }}</RouterLink>
            <span v-else :class="{ 'text-slate-600': item.done }">{{ item.text }}</span>
          </li>
        </ul>
      </section>

      <section class="card p-4">
        <h3 class="mb-3 font-semibold">Thème</h3>
        <div class="grid gap-3 sm:grid-cols-2">
          <button
            v-for="theme in state.themes"
            :key="theme.code"
            type="button"
            class="rounded-xl border-2 p-3 text-left"
            :class="theme.code === state.theme.code ? 'border-emerald-700' : 'border-slate-200 hover:border-slate-300'"
            :disabled="busy === 'theme'"
            @click="theme.code !== state.theme.code && activate(theme.code)"
          >
            <span class="mb-2 flex h-16 overflow-hidden rounded-lg border border-slate-200" :style="{ background: theme.defaults.background_color }">
              <span class="h-5 w-full" :style="{ background: theme.code === 'marche' ? theme.defaults.primary_color : '#fff', borderBottom: '1px solid #e2e8f0' }" />
            </span>
            <span class="flex items-center gap-2 font-medium">
              <span class="h-3 w-3 rounded-full" :style="{ background: theme.defaults.primary_color }" />
              {{ theme.name }}
              <span v-if="theme.code === state.theme.code" class="ml-auto text-xs text-emerald-700">Actif</span>
            </span>
            <span class="block text-sm text-slate-500">{{ theme.description }}</span>
          </button>
        </div>
      </section>

      <section class="card grid gap-4 p-4 sm:grid-cols-2">
        <div>
          <h3 class="mb-2 font-semibold">Logo</h3>
          <div class="flex items-center gap-3">
            <img v-if="state.logo_url" :src="state.logo_url" alt="Logo" class="h-16 w-16 rounded-full border border-slate-200 object-cover" />
            <span v-else class="grid h-16 w-16 place-items-center rounded-full bg-slate-100 text-xl font-semibold text-slate-500">{{ auth.store?.name?.[0] }}</span>
            <label class="btn-secondary cursor-pointer">
              {{ busy === 'logo' ? 'Envoi…' : state.logo_url ? 'Changer' : 'Ajouter' }}
              <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="upload('logo', $event)" />
            </label>
            <button v-if="state.logo_url" type="button" class="btn-ghost text-red-600" @click="removeImage('logo')">Retirer</button>
          </div>
        </div>
        <div>
          <h3 class="mb-2 font-semibold">Image de l'accueil</h3>
          <div class="flex items-center gap-3">
            <img v-if="state.theme.hero_image_url" :src="state.theme.hero_image_url" alt="" class="h-16 w-28 rounded-lg border border-slate-200 object-cover" />
            <span v-else class="grid h-16 w-28 place-items-center rounded-lg bg-slate-100 text-xs text-slate-500">Aucune</span>
            <label class="btn-secondary cursor-pointer">
              {{ busy === 'hero' ? 'Envoi…' : state.theme.hero_image_url ? 'Changer' : 'Ajouter' }}
              <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="upload('hero', $event)" />
            </label>
            <button v-if="state.theme.hero_image_url" type="button" class="btn-ghost text-red-600" @click="removeImage('hero')">Retirer</button>
          </div>
        </div>
      </section>

      <form class="card space-y-4 p-4" @submit.prevent="save">
        <h3 class="font-semibold">Réglages</h3>
        <div class="grid gap-4 sm:grid-cols-3">
          <Field v-for="field in colorFields" :id="`s-${field.key}`" :key="field.key" :label="field.label" :error="errors[field.key]">
            <div class="flex gap-2">
              <input :id="`s-${field.key}`" v-model="settings[field.key]" type="color" class="h-11 w-14 rounded border border-slate-300" />
              <input v-model="settings[field.key]" class="input font-mono" maxlength="7" :aria-label="`${field.label} (code)`" />
            </div>
          </Field>
        </div>
        <Field v-for="field in textFields" :id="`s-${field.key}`" :key="field.key" :label="field.label" :error="errors[field.key]" :hint="field.help">
          <select v-if="field.type === 'select'" :id="`s-${field.key}`" v-model="settings[field.key]" class="input">
            <option v-for="(label, value) in field.options" :key="value" :value="value">{{ label }}</option>
          </select>
          <select v-else-if="field.type === 'collection'" :id="`s-${field.key}`" v-model="settings[field.key]" class="input">
            <option :value="null">Aucune</option>
            <option v-for="c in state.collections" :key="c.id" :value="c.id">{{ c.title }}</option>
          </select>
          <textarea v-else-if="field.type === 'textarea'" :id="`s-${field.key}`" v-model="settings[field.key]" class="input" rows="2" :maxlength="field.max" />
          <input v-else :id="`s-${field.key}`" v-model="settings[field.key]" class="input" :maxlength="field.max" />
        </Field>
        <div class="space-y-2">
          <label v-for="field in toggles" :key="field.key" class="flex items-center gap-2 text-sm">
            <input v-model="settings[field.key]" type="checkbox" class="h-4 w-4 accent-emerald-700" />
            {{ field.label }}
          </label>
        </div>
        <div class="flex items-center gap-3">
          <span v-if="saved" class="text-sm text-emerald-700">Enregistré ✓</span>
          <button class="btn-brand ml-auto" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
        </div>
      </form>
    </template>
  </div>
</template>
