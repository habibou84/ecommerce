<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import Icon from '../../../components/Icon.vue'
import Modal from '../../../components/Modal.vue'
import Pagination from '../../../components/Pagination.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'
import { PAYMENT_TONES, SOURCES, STATUS_TONES, riskLabel } from '../../../lib/orders'
import { useAuthStore } from '../../../stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const TABS = [
  { value: '', label: 'Toutes' },
  { value: 'pending_confirmation', label: 'À confirmer' },
  { value: 'to_ship', label: 'À faire livrer' },
  { value: 'shipped', label: 'En route' },
  { value: 'completed', label: 'Livrées' },
  { value: 'cancelled', label: 'Annulées' },
  { value: 'returned', label: 'Retournées' },
]

const filters = reactive({ status: route.query.status ?? '', search: route.query.search ?? '', source: route.query.source ?? '', page: 1 })
const orders = ref([])
const counts = ref({})
const meta = ref(null)
const error = ref('')
const filtersOpen = ref(false)

async function load() {
  const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v !== null))
  router.replace({ query: { ...params, page: undefined } })
  try {
    const { data } = await http.get('/orders', { params })
    orders.value = data.data
    counts.value = data.counts
    meta.value = data.meta
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

let timer
watch(() => filters.search, () => {
  clearTimeout(timer)
  timer = setTimeout(() => { filters.page = 1; load() }, 300)
})
watch(() => [filters.status, filters.source], () => { filters.page = 1; load() })

const total = (c) => Object.entries(c).filter(([k]) => k !== 'to_ship').reduce((s, [, n]) => s + n, 0)
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center gap-2 sm:gap-3">
      <input v-model="filters.search" type="search" class="input min-w-0 flex-1 sm:max-w-xs" placeholder="N°, nom ou téléphone" />
      <!-- Téléphone : les filtres et les pages voisines dans un panneau -->
      <button type="button" class="btn-secondary relative shrink-0 px-3 sm:hidden" aria-label="Filtres" @click="filtersOpen = true">
        <Icon name="filter" :size="18" />Filtres
        <span v-if="filters.source" class="absolute -right-1 -top-1 h-3 w-3 rounded-full bg-emerald-600 ring-2 ring-white" />
      </button>
      <select v-model="filters.source" class="input hidden w-auto sm:block">
        <option value="">Tous les canaux</option>
        <option value="online_store">Site</option>
        <option v-for="s in SOURCES" :key="s.value" :value="s.value">{{ s.label }}</option>
      </select>
      <RouterLink v-if="auth.can('orders.manage')" to="/gestion/commandes/retours" class="btn-ghost hidden sm:ml-auto sm:inline-flex">Retours</RouterLink>
      <RouterLink v-if="auth.can('orders.manage')" to="/gestion/paniers-abandonnes" class="btn-ghost hidden sm:inline-flex">Paniers abandonnés</RouterLink>
      <RouterLink v-if="auth.can('orders.manage')" to="/gestion/commandes/nouvelle" class="btn-brand hidden lg:inline-flex"><Icon name="plus" :size="18" />Commande</RouterLink>
    </div>
    <Modal v-if="filtersOpen" title="Filtres" @close="filtersOpen = false">
      <div class="space-y-4">
        <div>
          <label class="label" for="order-source">Canal</label>
          <select id="order-source" v-model="filters.source" class="input">
            <option value="">Tous les canaux</option>
            <option value="online_store">Site</option>
            <option v-for="s in SOURCES" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
        </div>
        <div v-if="auth.can('orders.manage')" class="grid grid-cols-2 gap-2">
          <RouterLink to="/gestion/commandes/retours" class="btn-secondary">Retours</RouterLink>
          <RouterLink to="/gestion/paniers-abandonnes" class="btn-secondary">Paniers abandonnés</RouterLink>
        </div>
        <button type="button" class="btn-brand w-full py-3" @click="filtersOpen = false">Voir les commandes</button>
      </div>
    </Modal>

    <nav v-tab-strip class="-mx-4 flex gap-1 overflow-x-auto border-b border-slate-200 px-4 sm:mx-0 sm:px-0">
      <button
        v-for="tab in TABS"
        :key="tab.value"
        class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"
        :class="filters.status === tab.value ? 'border-emerald-700 text-emerald-800' : 'border-transparent text-slate-600 hover:text-slate-900'"
        @click="filters.status = tab.value"
      >
        {{ tab.label }}
        <span class="ml-1 rounded-full bg-slate-100 px-1.5 text-xs">{{ tab.value ? counts[tab.value] ?? 0 : total(counts) }}</span>
      </button>
    </nav>

    <Alert v-if="error">{{ error }}</Alert>

    <div class="card">
      <ul class="divide-y divide-slate-100">
        <li v-for="o in orders" :key="o.id">
          <RouterLink :to="`/gestion/commandes/${o.id}`" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 hover:bg-slate-50">
            <span class="w-16 font-semibold">{{ o.reference }}</span>
            <span class="min-w-0 flex-1">
              <span class="block truncate font-medium">{{ o.customer_name || o.customer_phone_display || 'Client au comptoir' }}</span>
              <span class="block truncate text-sm text-slate-500">
                {{ o.customer_phone_display }} · {{ o.delivery_method === 'pickup' ? 'Retrait' : o.shipping_place_label || 'Adresse à préciser' }} · {{ o.source_label }}
              </span>
            </span>
            <span class="text-right text-sm">
              <span class="block font-semibold">{{ money(o.total) }}</span>
              <span class="block" :class="PAYMENT_TONES[o.payment_status]">{{ o.payment_status_label }}</span>
            </span>
            <span class="flex w-full flex-wrap items-center gap-2 sm:w-44 sm:justify-end">
              <span v-if="riskLabel(o.risk_score)" class="rounded-full px-2 py-0.5 text-xs" :class="riskLabel(o.risk_score).tone">{{ riskLabel(o.risk_score).label }}</span>
              <span v-if="o.callback_at && o.status === 'pending_confirmation'" class="text-xs text-amber-800">Rappel {{ formatDateTime(o.callback_at) }}</span>
              <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="STATUS_TONES[o.status]">{{ o.status_label }}</span>
            </span>
          </RouterLink>
        </li>
        <li v-if="!orders.length" class="px-4 py-8 text-center text-sm text-slate-500">Aucune commande.</li>
      </ul>
    </div>
    <Pagination :meta="meta" @page="(p) => { filters.page = p; load() }" />
  </div>
</template>
