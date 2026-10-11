<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import OnlineStoreTabs from '../../../components/OnlineStoreTabs.vue'
import http, { errorMessage } from '../../../lib/http'

const menus = ref(null)
const collections = ref([])
const pages = ref([])
const error = ref('')
const saved = ref('')
const saving = ref('')

const titles = { main: 'Menu principal (en-tête)', footer: 'Pied de page' }
const types = [
  { value: 'home', label: 'Accueil' },
  { value: 'products', label: 'Tous les produits' },
  { value: 'collection', label: 'Collection' },
  { value: 'page', label: 'Page' },
  { value: 'url', label: 'Adresse web' },
]

onMounted(async () => {
  try {
    const [m, c, p] = await Promise.all([http.get('/menus'), http.get('/collections'), http.get('/pages')])
    menus.value = m.data.data
    collections.value = c.data.data
    pages.value = p.data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
})

function add(handle) {
  menus.value[handle].items.push({ label: '', type: 'products', target: null })
}

function move(items, index, delta) {
  const [item] = items.splice(index, 1)
  items.splice(index + delta, 0, item)
}

function changeType(item) {
  item.target = null
  if (!item.label) item.label = types.find((t) => t.value === item.type)?.label ?? ''
}

function pickTarget(item, list) {
  const found = list.find((x) => x.id === item.target)
  if (found && !item.label) item.label = found.title
}

async function save(handle) {
  saving.value = handle
  error.value = ''
  saved.value = ''
  try {
    menus.value = (await http.put(`/menus/${handle}`, { items: menus.value[handle].items })).data.data
    saved.value = handle
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    saving.value = ''
  }
}

async function reset(handle) {
  if (!confirm('Revenir au menu automatique ?')) return
  menus.value = (await http.delete(`/menus/${handle}`)).data.data
}
</script>

<template>
  <div class="space-y-4">
    <OnlineStoreTabs />
    <Alert v-if="error">{{ error }}</Alert>

    <template v-if="menus">
      <section v-for="handle in ['main', 'footer']" :key="handle" class="card space-y-3 p-4">
        <div class="flex items-center gap-2">
          <h3 class="font-semibold">{{ titles[handle] }}</h3>
          <span v-if="!menus[handle].custom" class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500">Automatique</span>
          <button v-else class="ml-auto text-sm text-slate-500 underline" @click="reset(handle)">Menu automatique</button>
        </div>
        <p v-if="!menus[handle].custom" class="text-sm text-slate-500">
          {{ handle === 'main' ? 'Accueil, Boutique et vos collections.' : 'Vos pages visibles.' }} Ajoutez un lien pour composer votre propre menu.
        </p>

        <ul class="space-y-2">
          <li v-for="(item, index) in menus[handle].items" :key="index" class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 p-2">
            <input v-model="item.label" class="input w-40 flex-1" placeholder="Libellé" maxlength="40" :aria-label="`Libellé du lien ${index + 1}`" />
            <select v-model="item.type" class="input w-auto" :aria-label="`Type du lien ${index + 1}`" @change="changeType(item)">
              <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
            <select v-if="item.type === 'collection'" v-model="item.target" class="input w-auto" @change="pickTarget(item, collections)">
              <option :value="null" disabled>Choisir…</option>
              <option v-for="c in collections" :key="c.id" :value="c.id">{{ c.title }}</option>
            </select>
            <select v-else-if="item.type === 'page'" v-model="item.target" class="input w-auto" @change="pickTarget(item, pages)">
              <option :value="null" disabled>Choisir…</option>
              <option v-for="p in pages" :key="p.id" :value="p.id">{{ p.title }}</option>
            </select>
            <input v-else-if="item.type === 'url'" v-model="item.target" class="input w-56" placeholder="https://…" />
            <span class="ml-auto flex">
              <button type="button" class="btn-ghost px-1.5 py-0.5" :disabled="index === 0" @click="move(menus[handle].items, index, -1)">↑</button>
              <button type="button" class="btn-ghost px-1.5 py-0.5" :disabled="index === menus[handle].items.length - 1" @click="move(menus[handle].items, index, 1)">↓</button>
              <button type="button" class="btn-ghost px-1.5 py-0.5 text-red-600" aria-label="Retirer" @click="menus[handle].items.splice(index, 1)">✕</button>
            </span>
          </li>
        </ul>
        <div class="flex items-center gap-3">
          <button type="button" class="btn-secondary px-3 py-1.5" :disabled="menus[handle].items.length >= 12" @click="add(handle)">+ Lien</button>
          <span v-if="saved === handle" class="text-sm text-emerald-700">Enregistré ✓</span>
          <button type="button" class="btn-brand ml-auto" :disabled="saving === handle" @click="save(handle)">Enregistrer</button>
        </div>
      </section>
    </template>
  </div>
</template>
