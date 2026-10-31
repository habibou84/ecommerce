<script setup>
import { computed, onMounted, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'

/**
 * Fonctions de la plateforme : masquée (invisible pour les marchands), test (boutiques choisies, par exemple pour la
 * vérification de Meta) ou active (tout le monde, dans la limite de l'offre). Masquer ne supprime rien.
 */
const data = ref(null)
const error = ref('')
const saved = ref('')
const busy = ref('')
const search = ref('')
const results = ref([])

const STATES = [
  ['hidden', 'Masquée', 'bg-slate-700 text-white'],
  ['test', 'Test', 'bg-amber-500 text-white'],
  ['on', 'Active', 'bg-emerald-700 text-white'],
]

onMounted(async () => {
  try {
    data.value = (await http.get('/console/features')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
})

const groups = computed(() => {
  const out = {}
  for (const f of data.value?.features ?? []) (out[f.group] ??= []).push(f)
  return out
})

async function setState(feature, state) {
  if (feature.state === state) return
  if (state === 'hidden' && !window.confirm(`Masquer « ${feature.label} » pour les marchands ? Rien n'est supprimé : les connexions sont mises en pause.`)) return
  busy.value = feature.key
  error.value = ''
  try {
    data.value = (await http.put(`/console/features/${feature.key}`, { state })).data.data
    saved.value = `« ${feature.label} » : ${STATES.find((s) => s[0] === state)[1].toLowerCase()}.`
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = ''
  }
}

let timer
function find() {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    if (search.value.trim().length < 2) {
      results.value = []
      return
    }
    results.value = (await http.get('/console/stores', { params: { search: search.value.trim() } })).data.data.slice(0, 8)
  }, 250)
}

async function saveTests(ids) {
  error.value = ''
  try {
    data.value = (await http.put('/console/features/test-stores', { store_ids: ids })).data.data
    saved.value = 'Boutiques de test enregistrées.'
  } catch (e) {
    error.value = errorMessage(e)
  }
}
const addTest = (store) => {
  search.value = ''
  results.value = []
  saveTests([...new Set([...data.value.test_stores.map((s) => s.id), store.id])])
}
const removeTest = (store) => saveTests(data.value.test_stores.filter((s) => s.id !== store.id).map((s) => s.id))
</script>

<template>
  <div class="space-y-5">
    <div>
      <h1 class="text-xl font-semibold">Fonctions</h1>
      <p class="mt-1 text-sm text-slate-600">
        <b>Masquée</b> : invisible pour les marchands, le serveur refuse les appels. <b>Test</b> : seulement pour les boutiques de test ci-dessous
        (par exemple pour la vérification de Meta). <b>Active</b> : pour tout le monde, dans la limite de l'offre. Masquer ne supprime rien : les
        connexions sont mises en pause. Chaque changement est noté dans le Journal.
      </p>
    </div>
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="saved" tone="success">{{ saved }}</Alert>

    <template v-if="data">
      <section class="card space-y-3 p-4 text-sm">
        <h2 class="font-semibold">Boutiques de test</h2>
        <p class="text-slate-600">Elles voient les fonctions en « Test ».</p>
        <div class="flex flex-wrap gap-2">
          <span v-for="s in data.test_stores" :key="s.id" class="inline-flex items-center gap-2 rounded-full bg-amber-100 px-3 py-1 text-amber-900">
            {{ s.name }} <button type="button" class="font-bold" :aria-label="`Retirer ${s.name}`" @click="removeTest(s)">✕</button>
          </span>
          <span v-if="!data.test_stores.length" class="text-slate-500">Aucune pour l'instant.</span>
        </div>
        <div class="relative max-w-md">
          <input v-model="search" class="input" placeholder="Ajouter une boutique (nom, adresse ou numéro)" @input="find" />
          <ul v-if="results.length" class="absolute inset-x-0 top-full z-10 mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white shadow">
            <li v-for="r in results" :key="r.id"><button type="button" class="w-full px-3 py-2 text-left hover:bg-slate-50" @click="addTest(r)">{{ r.name }} <span class="text-slate-500">· {{ r.slug }}</span></button></li>
          </ul>
        </div>
      </section>

      <section v-for="(features, group) in groups" :key="group" class="card p-4">
        <h2 class="mb-2 font-semibold">{{ group }}</h2>
        <ul class="divide-y divide-slate-100">
          <li v-for="f in features" :key="f.key" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center">
            <div class="min-w-0 flex-1">
              <p class="font-medium">{{ f.label }}</p>
              <p class="text-sm text-slate-500">{{ f.description }}</p>
            </div>
            <div class="inline-flex shrink-0 rounded-lg bg-slate-100 p-1 text-sm" role="radiogroup" :aria-label="f.label">
              <button v-for="[value, label, active] in STATES" :key="value" type="button" role="radio" :aria-checked="f.state === value" :disabled="busy === f.key"
                      class="rounded-md px-3 py-1.5 font-medium" :class="f.state === value ? active : 'text-slate-600'" @click="setState(f, value)">{{ label }}</button>
            </div>
          </li>
        </ul>
      </section>
    </template>
    <p v-else-if="!error" class="text-sm text-slate-500">Chargement…</p>
  </div>
</template>
