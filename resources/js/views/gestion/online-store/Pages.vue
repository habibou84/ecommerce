<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import OnlineStoreTabs from '../../../components/OnlineStoreTabs.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { useAuthStore } from '../../../stores/auth'

const auth = useAuthStore()
const pages = ref([])
const error = ref('')
const editing = ref(null)
const errors = ref({})
const saving = ref(false)

// Pages proposées à la création : le marchand n'a plus qu'à compléter
const suggestions = [
  { title: 'À propos', body: 'Présentez votre boutique : votre histoire, vos produits, ce qui vous rend unique.' },
  { title: 'Livraison et retours', body: '## Livraison\n\nLivraison sous 24 à 48 h à Abidjan. Paiement à la livraison.\n\n## Retours\n\nVous pouvez refuser un article non conforme à la livraison.' },
  { title: 'Conditions de vente', body: 'Vos conditions générales de vente.' },
]

async function load() {
  try {
    pages.value = (await http.get('/pages')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

function open(page, preset = null) {
  errors.value = {}
  editing.value = page ? { ...page } : { title: preset?.title ?? '', slug: '', body: preset?.body ?? '', is_visible: true, seo_title: '', seo_description: '' }
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    if (editing.value.id) await http.put(`/pages/${editing.value.id}`, editing.value)
    else await http.post('/pages', editing.value)
    editing.value = null
    await load()
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    saving.value = false
  }
}

async function destroy() {
  if (!confirm(`Supprimer la page « ${editing.value.title} » ?`)) return
  await http.delete(`/pages/${editing.value.id}`)
  editing.value = null
  await load()
}
</script>

<template>
  <div class="space-y-4">
    <OnlineStoreTabs />
    <div class="flex items-center justify-between gap-3">
      <p class="text-sm text-slate-600">Pages d'information de votre boutique, affichées en pied de page.</p>
      <button class="btn-brand shrink-0" @click="open(null)">+ Page</button>
    </div>
    <Alert v-if="error">{{ error }}</Alert>

    <div class="card">
      <div v-if="!pages.length" class="space-y-3 p-4 text-sm">
        <p class="text-slate-500">Aucune page pour l'instant. Commencez par :</p>
        <div class="flex flex-wrap gap-2">
          <button v-for="s in suggestions" :key="s.title" class="btn-secondary px-3 py-1.5" @click="open(null, s)">+ {{ s.title }}</button>
        </div>
      </div>
      <ul v-else class="divide-y divide-slate-100">
        <li v-for="p in pages" :key="p.id">
          <button class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-slate-50" @click="open(p)">
            <span class="min-w-0 flex-1">
              <span class="block font-medium">{{ p.title }}</span>
              <span class="block text-sm text-slate-500">/pages/{{ p.slug }}</span>
            </span>
            <span v-if="!p.is_visible" class="text-xs text-slate-500">Masquée</span>
          </button>
        </li>
      </ul>
    </div>

    <Modal v-if="editing" :title="editing.id ? editing.title : 'Nouvelle page'" wide @close="editing = null">
      <form class="space-y-4" @submit.prevent="save">
        <Field id="p-title" label="Titre" :error="errors.title">
          <input id="p-title" v-model="editing.title" class="input" maxlength="120" required />
        </Field>
        <Field id="p-body" label="Contenu" :error="errors.body" hint="Mise en forme simple : ## Titre, **gras**, - liste, [lien](https://…)">
          <textarea id="p-body" v-model="editing.body" class="input font-mono text-sm" rows="10" />
        </Field>
        <Field v-if="editing.id" id="p-slug" label="Adresse de la page" :error="errors.slug">
          <div class="flex items-center gap-1 text-sm">
            <span class="text-slate-500">/pages/</span>
            <input id="p-slug" v-model="editing.slug" class="input" maxlength="80" />
          </div>
        </Field>
        <details class="text-sm">
          <summary class="cursor-pointer text-slate-600">Référencement (Google)</summary>
          <div class="mt-3 space-y-3">
            <Field id="p-seo-title" label="Titre pour Google" :error="errors.seo_title">
              <input id="p-seo-title" v-model="editing.seo_title" class="input" maxlength="70" />
            </Field>
            <Field id="p-seo-description" label="Description pour Google" :error="errors.seo_description">
              <textarea id="p-seo-description" v-model="editing.seo_description" class="input" rows="2" maxlength="160" />
            </Field>
          </div>
        </details>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="editing.is_visible" type="checkbox" class="h-4 w-4 accent-emerald-700" />
          Visible sur la boutique
        </label>
        <div class="flex gap-2">
          <button v-if="editing.id" type="button" class="btn-ghost text-red-600" @click="destroy">Supprimer</button>
          <a v-if="editing.id && auth.store?.storefront_enabled" :href="`/pages/${editing.slug}`" target="_blank" class="btn-ghost">Voir</a>
          <button class="btn-brand ml-auto" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
