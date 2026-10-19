<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import Pagination from '../../../components/Pagination.vue'
import StockTabs from '../../../components/StockTabs.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { money } from '../../../lib/format'

const summary = ref(null)
const locations = ref([])
const filters = reactive({ location_id: '', search: '', filter: '', page: 1 })
const levels = ref([])
const meta = ref(null)
const error = ref('')

const adjusting = ref(null)
const adjust = reactive({ mode: 'delta', direction: 'in', quantity: '', on_hand: '', reason: 'adjustment', note: '', location_id: null })
const adjustErrors = ref({})
const busy = ref(false)

async function load() {
  try {
    const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''))
    const { data } = await http.get('/stock/levels', { params })
    levels.value = data.data
    meta.value = data.meta
  } catch (e) {
    error.value = errorMessage(e)
  }
}

async function loadSummary() {
  summary.value = (await http.get('/stock/summary')).data.data
}

onMounted(async () => {
  locations.value = (await http.get('/stock/locations')).data.data.filter((l) => l.is_active)
  await Promise.all([load(), loadSummary()])
})

let timer
watch(() => filters.search, () => {
  clearTimeout(timer)
  timer = setTimeout(() => { filters.page = 1; load() }, 300)
})
watch(() => [filters.location_id, filters.filter], () => { filters.page = 1; load() })

function openAdjust(level) {
  adjusting.value = level
  adjustErrors.value = {}
  Object.assign(adjust, { mode: 'delta', direction: 'in', quantity: '', on_hand: '', reason: 'adjustment', note: '', location_id: Number(filters.location_id) || locations.value[0]?.id })
}

async function saveAdjust() {
  busy.value = true
  adjustErrors.value = {}
  try {
    const payload = { variant_id: adjusting.value.variant_id, location_id: adjust.location_id, note: adjust.note || null }
    if (adjust.mode === 'count') payload.on_hand = Number(adjust.on_hand)
    else {
      payload.quantity = Number(adjust.quantity) * (adjust.direction === 'out' ? -1 : 1)
      payload.reason = adjust.direction === 'out' ? adjust.reason : 'adjustment'
    }
    await http.post('/stock/adjustments', payload)
    adjusting.value = null
    await Promise.all([load(), loadSummary()])
  } catch (e) {
    adjustErrors.value = fieldErrors(e)
    if (!Object.keys(adjustErrors.value).length) adjustErrors.value = { quantity: errorMessage(e) }
  } finally {
    busy.value = false
  }
}

const levelIn = (row, locationId) => row.levels.find((l) => l.location_id === locationId)?.on_hand ?? 0
</script>

<template>
  <div class="space-y-4">
    <StockTabs />

    <div v-if="summary" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
      <button class="card p-3 text-left hover:border-amber-400" @click="filters.filter = 'low'">
        <p class="text-xs text-slate-500">Stock bas</p>
        <p class="text-2xl font-semibold" :class="summary.low ? 'text-amber-700' : ''">{{ summary.low }}</p>
      </button>
      <button class="card p-3 text-left hover:border-red-400" @click="filters.filter = 'out'">
        <p class="text-xs text-slate-500">Épuisés</p>
        <p class="text-2xl font-semibold" :class="summary.out ? 'text-red-600' : ''">{{ summary.out }}</p>
      </button>
      <div class="card p-3">
        <p class="text-xs text-slate-500">Articles en stock</p>
        <p class="text-2xl font-semibold">{{ summary.units }}</p>
      </div>
      <div class="card p-3">
        <p class="text-xs text-slate-500">Valeur (au coût)</p>
        <p class="text-2xl font-semibold">{{ money(summary.value) }}</p>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-2 sm:flex">
      <input v-model="filters.search" type="search" class="input col-span-2 sm:max-w-xs" placeholder="Rechercher un article" />
      <select v-if="locations.length > 1" v-model="filters.location_id" class="input sm:w-auto">
        <option value="">Tous les emplacements</option>
        <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option>
      </select>
      <select v-model="filters.filter" class="input sm:w-auto" :class="{ 'col-span-2': locations.length < 2 }">
        <option value="">Tous les articles</option>
        <option value="low">Stock bas</option>
        <option value="out">Épuisés</option>
      </select>
    </div>

    <Alert v-if="error">{{ error }}</Alert>

    <!-- Téléphone : une carte par article, le bouton « Ajuster » toujours visible -->
    <ul class="space-y-2 sm:hidden">
      <li v-for="row in levels" :key="row.variant_id" class="card flex items-center gap-3 p-3">
        <div class="min-w-0 flex-1">
          <RouterLink :to="`/gestion/catalogue/${row.product_id}`" class="block font-medium leading-snug">{{ row.title }}</RouterLink>
          <p class="mt-0.5 text-xs text-slate-500">
            <template v-if="row.sku">{{ row.sku }} · </template>{{ row.reserved }} réservé(s)
          </p>
        </div>
        <div class="text-right">
          <p class="text-lg font-semibold leading-none tabular-nums" :class="row.total_available <= 0 ? 'text-red-600' : row.total_available <= row.threshold ? 'text-amber-700' : ''">{{ row.available }}</p>
          <p class="text-xs text-slate-500">dispo.</p>
        </div>
        <button class="btn-secondary shrink-0 px-3 py-2" @click="openAdjust(row)">Ajuster</button>
      </li>
      <li v-if="!levels.length" class="card p-6 text-center text-sm text-slate-500">Aucun article.</li>
    </ul>

    <div class="card hidden overflow-x-auto sm:block">
      <table class="w-full text-sm">
        <thead class="border-b border-slate-100 text-left text-xs uppercase text-slate-500">
          <tr>
            <th class="px-4 py-2 font-medium">Article</th>
            <template v-if="!filters.location_id && locations.length > 1">
              <th v-for="l in locations" :key="l.id" class="hidden px-3 py-2 text-right font-medium md:table-cell">{{ l.name }}</th>
            </template>
            <th class="px-3 py-2 text-right font-medium">Réservé</th>
            <th class="px-3 py-2 text-right font-medium">Disponible</th>
            <th class="px-3 py-2" />
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="row in levels" :key="row.variant_id">
            <td class="px-4 py-2">
              <RouterLink :to="`/gestion/catalogue/${row.product_id}`" class="font-medium hover:text-emerald-700">{{ row.title }}</RouterLink>
              <span v-if="row.sku" class="block text-xs text-slate-400">{{ row.sku }}</span>
            </td>
            <template v-if="!filters.location_id && locations.length > 1">
              <td v-for="l in locations" :key="l.id" class="hidden px-3 py-2 text-right md:table-cell">{{ levelIn(row, l.id) }}</td>
            </template>
            <td class="px-3 py-2 text-right text-slate-500">{{ row.reserved }}</td>
            <td class="px-3 py-2 text-right font-semibold" :class="row.total_available <= 0 ? 'text-red-600' : row.total_available <= row.threshold ? 'text-amber-700' : ''">{{ row.available }}</td>
            <td class="px-3 py-2 text-right"><button class="btn-secondary px-2.5 py-1" @click="openAdjust(row)">Ajuster</button></td>
          </tr>
          <tr v-if="!levels.length"><td colspan="9" class="px-4 py-6 text-center text-slate-500">Aucun article.</td></tr>
        </tbody>
      </table>
    </div>
    <Pagination :meta="meta" @page="(p) => { filters.page = p; load() }" />

    <Modal v-if="adjusting" :title="adjusting.title" @close="adjusting = null">
      <form class="space-y-4" @submit.prevent="saveAdjust">
        <Field v-if="locations.length > 1" id="a-location" label="Emplacement">
          <select id="a-location" v-model="adjust.location_id" class="input">
            <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }} ({{ levelIn(adjusting, l.id) }})</option>
          </select>
        </Field>
        <div class="grid grid-cols-2 gap-2">
          <button type="button" class="btn" :class="adjust.mode === 'delta' ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-600' : 'btn-secondary'" @click="adjust.mode = 'delta'">Entrée / sortie</button>
          <button type="button" class="btn" :class="adjust.mode === 'count' ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-600' : 'btn-secondary'" @click="adjust.mode = 'count'">Quantité comptée</button>
        </div>
        <template v-if="adjust.mode === 'delta'">
          <div class="flex gap-2">
            <select v-model="adjust.direction" class="input w-auto">
              <option value="in">Ajouter</option>
              <option value="out">Retirer</option>
            </select>
            <input v-model="adjust.quantity" class="input" type="number" min="1" placeholder="Quantité" required />
          </div>
          <select v-if="adjust.direction === 'out'" v-model="adjust.reason" class="input">
            <option value="adjustment">Correction</option>
            <option value="damaged">Abîmé, perdu ou volé</option>
          </select>
        </template>
        <Field v-else id="a-count" label="Quantité réellement présente" hint="Elle remplace la quantité en stock de cet emplacement.">
          <input id="a-count" v-model="adjust.on_hand" class="input" type="number" min="0" required />
        </Field>
        <Field id="a-note" label="Note (facultatif)">
          <input id="a-note" v-model="adjust.note" class="input" maxlength="255" />
        </Field>
        <p v-if="adjustErrors.quantity || adjustErrors.on_hand" class="text-sm text-red-600">{{ adjustErrors.quantity || adjustErrors.on_hand }}</p>
        <button class="btn-brand w-full" :disabled="busy">Enregistrer</button>
      </form>
    </Modal>
  </div>
</template>
