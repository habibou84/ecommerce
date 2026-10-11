<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { useAuthStore } from '../../../stores/auth'

const auth = useAuthStore()
const collections = ref([])
const error = ref('')
const editing = ref(null) // { id?, title, description, is_visible_online, products: [] }
const errors = ref({})
const saving = ref(false)
const search = ref('')
const results = ref([])

async function load() {
  try {
    collections.value = (await http.get('/collections')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function open(collection) {
  errors.value = {}
  search.value = ''
  results.value = []
  if (!collection) {
    editing.value = { title: '', description: '', is_visible_online: true, products: [] }
    return
  }
  const { data } = await http.get(`/collections/${collection.id}`)
  editing.value = { ...data.data }
}

let timer
function find() {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    if (!search.value.trim()) return (results.value = [])
    const { data } = await http.get('/products', { params: { search: search.value, per_page: 10 } })
    results.value = data.data.filter((p) => !editing.value.products.some((x) => x.id === p.id))
  }, 250)
}

function add(product) {
  editing.value.products.push({ id: product.id, title: product.title })
  results.value = results.value.filter((p) => p.id !== product.id)
}

function move(index, delta) {
  const list = editing.value.products
  const [item] = list.splice(index, 1)
  list.splice(index + delta, 0, item)
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...editing.value, product_ids: editing.value.products.map((p) => p.id) }
    if (editing.value.id) await http.put(`/collections/${editing.value.id}`, payload)
    else await http.post('/collections', payload)
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
  if (!confirm(`Supprimer la collection « ${editing.value.title} » ? Les produits ne sont pas supprimés.`)) return
  await http.delete(`/collections/${editing.value.id}`)
  editing.value = null
  await load()
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between gap-3">
      <p class="text-sm text-slate-600">Regroupez vos produits (Nouveautés, Robes, Promotions…) : ce sont les rayons de votre boutique en ligne.</p>
      <button v-if="auth.can('products.manage')" class="btn-brand shrink-0" @click="open(null)">+ Collection</button>
    </div>
    <Alert v-if="error">{{ error }}</Alert>

    <div class="card">
      <p v-if="!collections.length" class="p-6 text-sm text-slate-500">Aucune collection pour l'instant.</p>
      <ul v-else class="divide-y divide-slate-100">
        <li v-for="c in collections" :key="c.id">
          <button class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-slate-50" @click="open(c)">
            <span class="min-w-0 flex-1">
              <span class="block font-medium">{{ c.title }}</span>
              <span class="block text-sm text-slate-500">{{ c.products_count }} produit{{ c.products_count > 1 ? 's' : '' }}</span>
            </span>
            <span v-if="!c.is_visible_online" class="text-xs text-slate-500">Masquée</span>
          </button>
        </li>
      </ul>
    </div>

    <Modal v-if="editing" :title="editing.id ? editing.title : 'Nouvelle collection'" wide @close="editing = null">
      <form class="space-y-4" @submit.prevent="save">
        <fieldset :disabled="!auth.can('products.manage')" class="space-y-4">
          <Field id="c-title" label="Nom" :error="errors.title">
            <input id="c-title" v-model="editing.title" class="input" maxlength="120" required />
          </Field>
          <Field id="c-description" label="Description">
            <textarea id="c-description" v-model="editing.description" class="input" rows="2" />
          </Field>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="editing.is_visible_online" type="checkbox" class="h-4 w-4 accent-emerald-700" />
            Visible sur la boutique en ligne
          </label>

          <div>
            <p class="label">Produits ({{ editing.products.length }})</p>
            <input v-model="search" class="input" placeholder="Ajouter un produit : tapez son nom" @input="find" />
            <ul v-if="results.length" class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200">
              <li v-for="p in results" :key="p.id">
                <button type="button" class="w-full px-3 py-2 text-left text-sm hover:bg-emerald-50" @click="add(p)">+ {{ p.title }}</button>
              </li>
            </ul>
            <ul class="mt-2 divide-y divide-slate-100 rounded-lg border border-slate-200">
              <li v-for="(p, index) in editing.products" :key="p.id" class="flex items-center gap-2 px-3 py-2 text-sm">
                <span class="w-6 text-slate-400">{{ index + 1 }}</span>
                <span class="min-w-0 flex-1 truncate">{{ p.title }}</span>
                <button type="button" class="btn-ghost px-1.5 py-0.5" :disabled="index === 0" @click="move(index, -1)">↑</button>
                <button type="button" class="btn-ghost px-1.5 py-0.5" :disabled="index === editing.products.length - 1" @click="move(index, 1)">↓</button>
                <button type="button" class="btn-ghost px-1.5 py-0.5 text-red-600" @click="editing.products.splice(index, 1)">✕</button>
              </li>
              <li v-if="!editing.products.length" class="px-3 py-2 text-sm text-slate-500">Aucun produit.</li>
            </ul>
          </div>
        </fieldset>

        <div v-if="auth.can('products.manage')" class="flex gap-2">
          <button v-if="editing.id" type="button" class="btn-ghost text-red-600" @click="destroy">Supprimer</button>
          <button class="btn-brand ml-auto" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
