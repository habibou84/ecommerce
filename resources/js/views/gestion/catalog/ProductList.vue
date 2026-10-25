<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
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
  page: Number(route.query.page ?? 1),
})
const products = ref([])
const meta = ref(null)
const collections = ref([])
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v !== null))
  router.replace({ query: params })
  try {
    const { data } = await http.get('/products', { params })
    products.value = data.data
    meta.value = data.meta
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
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
watch(() => [filters.status, filters.collection_id, filters.stock], () => {
  filters.page = 1
  load()
})

onMounted(async () => {
  load()
  collections.value = (await http.get('/collections')).data.data
})

function stockLabel(p) {
  if (!p.track_inventory) return 'Stock non suivi'
  if (p.available <= 0) return 'Épuisé'
  return `${p.available} en stock`
}
// Pastille de stock : vert s'il en reste, ocre si stock bas, rouge si épuisé
const stockTone = (p) => (!p.track_inventory ? 'bg-slate-100 text-slate-600' : p.available <= 0 ? 'bg-red-50 text-red-700' : p.low_variants ? 'bg-ocre-50 text-ocre-700' : 'bg-emerald-50 text-emerald-800')
const STOCK_CHIPS = [['', 'Tous'], ['low', 'Stock bas'], ['out', 'Épuisés']]
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-center">
      <input v-model="filters.search" type="search" class="input lg:max-w-xs" placeholder="Rechercher (nom, SKU, code-barres)" />
      <!-- Filtres : deux par ligne sur téléphone, à la suite sur ordinateur, jamais coupés -->
      <div class="grid flex-1 grid-cols-2 gap-2 sm:flex sm:flex-wrap">
        <select v-model="filters.status" class="input col-span-2 sm:w-auto">
          <option value="">Tous les statuts</option>
          <option value="active">Actifs</option>
          <option value="draft">Brouillons</option>
          <option value="archived">Archivés</option>
        </select>
        <select v-if="collections.length" v-model="filters.collection_id" class="input col-span-2 sm:w-auto sm:min-w-52">
          <option value="">Toutes les collections</option>
          <option v-for="c in collections" :key="c.id" :value="c.id">{{ c.title }}</option>
        </select>
      </div>
      <div v-if="auth.can('products.manage')" class="flex gap-2 lg:ml-auto">
        <RouterLink to="/gestion/catalogue/import" class="btn-ghost">Importer</RouterLink>
        <RouterLink to="/gestion/catalogue/nouveau" class="btn-brand flex-1 sm:flex-none"><Icon name="plus" :size="18" />Produit</RouterLink>
      </div>
    </div>

    <div class="flex gap-2">
      <button v-for="c in STOCK_CHIPS" :key="c[0]" type="button" class="min-h-10 rounded-xl border px-3.5 text-sm font-semibold" :class="filters.stock === c[0] ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700'" @click="filters.stock = c[0]">{{ c[1] }}</button>
    </div>

    <Alert v-if="error">{{ error }}</Alert>

    <p v-if="loading && !products.length" class="text-sm text-slate-500">Chargement…</p>
    <div v-else-if="!products.length" class="card p-8 text-center">
      <p class="font-medium">{{ filters.search || filters.status || filters.stock ? 'Aucun produit ne correspond.' : 'Votre catalogue est vide.' }}</p>
      <p v-if="!filters.search && auth.can('products.manage')" class="mt-1 text-sm text-slate-500">
        Ajoutez votre premier produit, ou importez un fichier Excel.
      </p>
    </div>
    <!-- Grille de cartes : photo (ou symbole photo), nom, prix, stock -->
    <ul v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
      <li v-for="p in products" :key="p.id">
        <RouterLink :to="`/gestion/catalogue/${p.id}`" class="card block h-full p-2 transition hover:border-emerald-600">
          <span class="relative block aspect-square overflow-hidden rounded-xl bg-slate-100">
            <img v-if="p.thumb" :src="p.thumb" alt="" class="h-full w-full object-cover" loading="lazy" />
            <span v-else class="flex h-full w-full flex-col items-center justify-center gap-2 text-slate-400">
              <Icon name="image" :size="34" />
              <span v-if="auth.can('products.manage')" class="rounded-full bg-white px-2.5 py-1 text-xs font-bold text-emerald-700">+ Ajouter une photo</span>
            </span>
            <span v-if="p.status !== 'active'" class="absolute left-2 top-2"><StatusBadge :status="p.status" :label="p.status_label" /></span>
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
      </li>
    </ul>

    <Pagination :meta="meta" @page="(page) => { filters.page = page; load() }" />
  </div>
</template>
