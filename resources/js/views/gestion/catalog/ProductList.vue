<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
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
  if (!p.track_inventory) return 'Non suivi'
  return `${p.available} disponible${p.available > 1 ? 's' : ''}${p.has_variants ? ` · ${p.variants_count} variantes` : ''}`
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
      <input v-model="filters.search" type="search" class="input sm:max-w-xs" placeholder="Rechercher (nom, SKU, code-barres)" />
      <div class="flex flex-1 gap-2 overflow-x-auto">
        <select v-model="filters.status" class="input w-auto">
          <option value="">Tous les statuts</option>
          <option value="active">Actifs</option>
          <option value="draft">Brouillons</option>
          <option value="archived">Archivés</option>
        </select>
        <select v-model="filters.stock" class="input w-auto">
          <option value="">Tout le stock</option>
          <option value="low">Stock bas</option>
          <option value="out">Épuisés</option>
        </select>
        <select v-if="collections.length" v-model="filters.collection_id" class="input w-auto">
          <option value="">Toutes les collections</option>
          <option v-for="c in collections" :key="c.id" :value="c.id">{{ c.title }}</option>
        </select>
      </div>
      <div v-if="auth.can('products.manage')" class="flex gap-2">
        <RouterLink to="/gestion/catalogue/import" class="btn-secondary">Importer</RouterLink>
        <RouterLink to="/gestion/catalogue/nouveau" class="btn-brand">+ Produit</RouterLink>
      </div>
    </div>

    <Alert v-if="error">{{ error }}</Alert>

    <div class="card overflow-hidden">
      <p v-if="loading && !products.length" class="p-6 text-sm text-slate-500">Chargement…</p>
      <div v-else-if="!products.length" class="p-8 text-center">
        <p class="font-medium">{{ filters.search || filters.status || filters.stock ? 'Aucun produit ne correspond.' : 'Votre catalogue est vide.' }}</p>
        <p v-if="!filters.search && auth.can('products.manage')" class="mt-1 text-sm text-slate-500">
          Ajoutez votre premier produit, ou importez un fichier Excel.
        </p>
      </div>
      <ul v-else class="divide-y divide-slate-100">
        <li v-for="p in products" :key="p.id">
          <RouterLink :to="`/gestion/catalogue/${p.id}`" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50">
            <img v-if="p.thumb" :src="p.thumb" alt="" class="h-12 w-12 shrink-0 rounded-lg object-cover" loading="lazy" />
            <span v-else class="grid h-12 w-12 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-400">📷</span>
            <span class="min-w-0 flex-1">
              <span class="block truncate font-medium">{{ p.title }}</span>
              <span class="block text-sm" :class="p.track_inventory && p.available <= 0 ? 'text-red-600' : p.low_variants ? 'text-amber-700' : 'text-slate-500'">
                {{ stockLabel(p) }}
              </span>
            </span>
            <span class="hidden text-right text-sm sm:block">{{ priceRange(p.price_min, p.price_max) }}</span>
            <StatusBadge :status="p.status" :label="p.status_label" />
          </RouterLink>
        </li>
      </ul>
    </div>

    <Pagination :meta="meta" @page="(page) => { filters.page = page; load() }" />
  </div>
</template>
