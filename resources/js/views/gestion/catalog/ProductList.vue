<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import Icon from '../../../components/Icon.vue'
import Pagination from '../../../components/Pagination.vue'
import StatusBadge from '../../../components/StatusBadge.vue'
import http, { errorMessage } from '../../../lib/http'
import { priceRange } from '../../../lib/format'
import { useAuthStore } from '../../../stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const filters = reactive({
  search: route.query.search ?? '',
  status: route.query.status ?? '',
  collection_id: route.query.collection_id ?? '',
  stock: route.query.stock ?? '',
  pack: route.query.pack ?? '',
  sort: route.query.sort ?? '',
  page: Number(route.query.page ?? 1),
})
const products = ref([])
const meta = ref(null)
const collections = ref([])
const loading = ref(true)
const error = ref('')

// Seule la dernière requête compte : une réponse plus ancienne, arrivée après, est ignorée
let requestId = 0
async function load() {
  const id = ++requestId
  loading.value = true
  error.value = ''
  const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v !== null))
  router.replace({ query: params })
  try {
    const { data } = await http.get('/products', { params })
    if (id !== requestId) return
    products.value = data.data
    meta.value = data.meta
  } catch (e) {
    if (id === requestId) error.value = errorMessage(e)
  } finally {
    if (id === requestId) loading.value = false
  }
}

let timer
watch(() => filters.search, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    filters.page = 1
    load()
  }, 300)
})
watch(() => [filters.status, filters.collection_id, filters.stock, filters.pack, filters.sort], () => {
  filters.page = 1
  load()
})

onMounted(async () => {
  load()
  try {
    collections.value = (await http.get('/collections')).data.data
  } catch (e) {
    collectionsError.value = true
  }
})

const collectionsError = ref(false)
const filtered = computed(() => !!(filters.search || filters.status || filters.collection_id || filters.stock || filters.pack))
function resetFilters() {
  Object.assign(filters, { search: '', status: '', collection_id: '', stock: '', pack: '', page: 1 })
}

// Menu ⋯ d'une carte : dupliquer ou supprimer sans ouvrir la fiche
const openMenu = ref(null)
async function duplicate(p) {
  openMenu.value = null
  try {
    const { data } = await http.post(`/products/${p.id}/duplicate`)
    router.push(`/gestion/catalogue/${data.data.id}`)
  } catch (e) {
    error.value = errorMessage(e)
  }
}
async function destroy(p) {
  openMenu.value = null
  if (!confirm(`Supprimer « ${p.title} » ? Son stock sera remis à zéro.`)) return
  try {
    await http.delete(`/products/${p.id}`)
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  }
}

function stockLabel(p) {
  if (!p.track_inventory) return 'Stock non suivi'
  if (p.available <= 0) return 'Épuisé'
  return `${p.available} en stock`
}
// Pastille de stock : vert s'il en reste, ocre si stock bas, rouge si épuisé
const stockTone = (p) => (!p.track_inventory ? 'bg-slate-100 text-slate-600' : p.available <= 0 ? 'bg-red-50 text-red-700' : p.low_variants ? 'bg-ocre-50 text-ocre-700' : 'bg-emerald-50 text-emerald-800')
const STOCK_CHIPS = [['', 'Tous'], ['low', 'Stock bas'], ['out', 'Épuisés']]
const SORTS = [['', 'Récents'], ['name', 'Nom (A → Z)'], ['price_asc', 'Prix croissant'], ['price_desc', 'Prix décroissant']]
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-center">
      <input v-model="filters.search" type="search" class="input lg:max-w-xs" placeholder="Rechercher (nom, SKU, code-barres)" />
      <!-- Filtres : deux par ligne sur téléphone, à la suite sur ordinateur, jamais coupés -->
      <div class="grid flex-1 grid-cols-2 gap-2 sm:flex sm:flex-wrap">
        <select v-model="filters.status" aria-label="Filtrer par statut" class="input col-span-2 sm:w-auto">
          <option value="">Tous les statuts</option>
          <option value="active">Actifs</option>
          <option value="draft">Brouillons</option>
          <option value="archived">Archivés</option>
        </select>
        <select v-if="collections.length" v-model="filters.collection_id" aria-label="Filtrer par collection" class="input col-span-2 sm:w-auto sm:min-w-52">
          <option value="">Toutes les collections</option>
          <option v-for="c in collections" :key="c.id" :value="c.id">{{ c.title }}</option>
        </select>
        <select v-model="filters.sort" aria-label="Trier les produits" class="input col-span-2 sm:w-auto">
          <option v-for="s in SORTS" :key="s[0]" :value="s[0]">Tri : {{ s[1] }}</option>
        </select>
      </div>
      <div v-if="auth.can('products.manage')" class="flex gap-2 lg:ml-auto">
        <RouterLink to="/gestion/catalogue/import" class="btn-ghost">Importer</RouterLink>
        <RouterLink to="/gestion/catalogue/nouveau" class="btn-brand flex-1 sm:flex-none"><Icon name="plus" :size="18" />Produit</RouterLink>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-2">
      <button v-for="c in STOCK_CHIPS" :key="c[0]" type="button" :aria-pressed="filters.stock === c[0]" class="min-h-10 rounded-xl border px-3.5 text-sm font-semibold" :class="filters.stock === c[0] ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700'" @click="filters.stock = c[0]">{{ c[1] }}</button>
      <button type="button" :aria-pressed="!!filters.pack" class="min-h-10 rounded-xl border px-3.5 text-sm font-semibold" :class="filters.pack ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700'" @click="filters.pack = filters.pack ? '' : '1'">Packs</button>
      <span v-if="meta" class="ml-auto text-sm text-slate-500">{{ meta.total }} produit{{ meta.total > 1 ? 's' : '' }}</span>
    </div>

    <Alert v-if="error">{{ error }}</Alert>
    <p v-if="collectionsError" class="text-sm text-slate-500">Les collections n'ont pas pu être chargées : le filtre par collection est masqué.</p>

    <p v-if="loading && !products.length" class="text-sm text-slate-500">Chargement…</p>
    <div v-else-if="!products.length" class="card p-8 text-center">
      <p class="font-medium">{{ filtered ? 'Aucun produit ne correspond.' : 'Vous n\'avez pas encore de produit.' }}</p>
      <button v-if="filtered" type="button" class="btn-ghost mt-3" @click="resetFilters">Réinitialiser les filtres</button>
      <p v-else-if="auth.can('products.manage')" class="mt-1 text-sm text-slate-500">
        Ajoutez votre premier produit, ou importez un fichier Excel.
      </p>
    </div>
    <!-- Grille de cartes : photo (ou symbole photo), nom, prix, stock -->
    <ul v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
      <li v-for="p in products" :key="p.id" class="relative">
        <RouterLink :to="`/gestion/catalogue/${p.id}`" class="card block h-full p-2 transition hover:border-emerald-600">
          <span class="relative block aspect-square overflow-hidden rounded-xl bg-slate-100">
            <img v-if="p.thumb" :src="p.thumb" alt="" class="h-full w-full object-cover" loading="lazy" />
            <span v-else class="flex h-full w-full flex-col items-center justify-center gap-2 text-slate-400">
              <Icon name="image" :size="34" />
              <span v-if="auth.can('products.manage')" class="rounded-full bg-white px-2.5 py-1 text-xs font-bold text-emerald-700">+ Ajouter une photo</span>
            </span>
            <span v-if="p.status !== 'active'" class="absolute left-2 top-2"><StatusBadge :status="p.status" :label="p.status_label" /></span>
            <span v-if="p.is_bundle" class="absolute bottom-2 left-2 rounded-full bg-slate-900 px-2 py-0.5 text-xs font-bold text-white">Pack</span>
          </span>
          <span class="block px-1 pb-1 pt-2">
            <span class="block truncate text-sm font-bold">{{ p.title }}</span>
            <span class="mt-0.5 block font-display text-base font-extrabold">{{ priceRange(p.price_min, p.price_max) }}</span>
            <span class="mt-1.5 flex flex-wrap items-center gap-1">
              <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="stockTone(p)">{{ stockLabel(p) }}</span>
              <span v-if="p.has_variants" class="text-xs text-slate-500">{{ p.variants_count }} variantes</span>
            </span>
          </span>
        </RouterLink>
        <div v-if="auth.can('products.manage')" class="absolute right-3 top-3">
          <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full bg-white/90 text-lg font-bold shadow" :aria-label="`Actions pour ${p.title}`" :aria-expanded="openMenu === p.id" @click="openMenu = openMenu === p.id ? null : p.id">⋯</button>
          <div v-if="openMenu === p.id" class="absolute right-0 z-10 mt-1 w-40 overflow-hidden rounded-xl border border-slate-200 bg-white text-sm shadow-lg">
            <button type="button" class="block w-full px-4 py-2.5 text-left hover:bg-slate-50" @click="duplicate(p)">Dupliquer</button>
            <button type="button" class="block w-full px-4 py-2.5 text-left text-red-600 hover:bg-red-50" @click="destroy(p)">Supprimer</button>
          </div>
        </div>
      </li>
    </ul>

    <Pagination :meta="meta" @page="(page) => { filters.page = page; load() }" />
  </div>
</template>
