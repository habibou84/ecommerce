<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import http from '../lib/http'
import { money } from '../lib/format'

// Lieu de livraison en touchant : les communes de la ville de la boutique (les plus livrées d'abord, avec leur prix)
// sur une ligne qu'on fait glisser (téléphone et ordinateur) ; la commune touchée, son quartier se choisit dans une
// liste déroulante allumée en orange (rouge si on l'oublie), avec une recherche. « Autre ville » pour une expédition.
const props = defineProps({
  rates: { type: Object, required: true }, // GET /delivery-rates
  showFees: { type: Boolean, default: true },
  invalid: { type: Boolean, default: false }, // quartier oublié : la liste passe en rouge
})
// pending : la commune touchée dont le quartier reste à choisir (ou null)
const emit = defineEmits(['pick', 'search', 'pending'])

const commune = ref(null) // { id, name, fee }
const quartiers = ref([])
const loading = ref(false)
const cities = ref(false)
const listOpen = ref(false)
const query = ref('')
const root = ref(null)
const searchInput = ref(null)

const home = computed(() => props.rates.home)

const feeText = (p) => (p.fee === null || p.fee === undefined ? 'prix à indiquer' : p.fee === 0 ? 'offerte' : money(p.fee))
const normalize = (v) => String(v ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
const filtered = computed(() => (query.value.trim() ? quartiers.value.filter((q) => normalize(q.name).includes(normalize(query.value.trim()))) : quartiers.value))

async function open(p) {
  if (!p.has_children) return pick(p, [home.value?.id])
  commune.value = p
  emit('pending', p.name)
  query.value = ''
  loading.value = true
  try {
    quartiers.value = (await http.get(`/delivery-rates/places/${p.id}`)).data.data.places
  } finally {
    loading.value = false
  }
  // La liste se montre à l'écran : on ne rate pas qu'il reste le quartier
  await nextTick()
  root.value?.querySelector('[data-quartier]')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

function pick(p, ancestors) {
  listOpen.value = false
  emit('pending', null)
  emit('pick', { id: p.id, label: p.label ?? p.name, ancestor_ids: ancestors.filter(Boolean) })
}

async function toggleList() {
  listOpen.value = !listOpen.value
  if (listOpen.value) {
    query.value = ''
    await nextTick()
    searchInput.value?.focus()
  }
}
// Toucher ailleurs ferme la liste
function outside(e) {
  if (listOpen.value && root.value && !root.value.contains(e.target)) listOpen.value = false
}
onMounted(() => document.addEventListener('pointerdown', outside))
onBeforeUnmount(() => document.removeEventListener('pointerdown', outside))
</script>

<template>
  <!-- Lignes qu'on fait glisser (comme le canal de vente) : communes, puis quartiers ou villes -->
  <div class="space-y-1.5">
    <div v-tab-strip class="-mx-4 flex gap-1.5 overflow-x-auto px-4 sm:-mx-5 sm:px-5">
      <button v-for="p in rates.places" :key="p.id" type="button" class="shrink-0 whitespace-nowrap rounded-full border px-3 py-1.5 text-sm font-semibold"
              :class="commune?.id === p.id ? 'border-emerald-700 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-white text-slate-700 hover:border-emerald-500'" :aria-pressed="commune?.id === p.id" @click="cities = false; open(p)">
        {{ p.name }}<span v-if="showFees" class="ml-1 text-xs font-bold" :class="p.learned ? 'text-emerald-700' : 'text-slate-500'">{{ feeText(p) }}</span>
      </button>
      <button v-if="rates.cities.length" type="button" class="shrink-0 whitespace-nowrap rounded-full border border-dashed px-3 py-1.5 text-sm font-semibold"
              :class="cities ? 'border-sky-500 bg-sky-50 text-sky-900' : 'border-sky-300 text-sky-800'" :aria-pressed="cities" @click="commune = null; emit('pending', null); cities = !cities">📦 Autre ville</button>
    </div>

    <!-- Quartier de la commune touchée : une liste déroulante allumée, avec une recherche -->
    <div v-if="commune" ref="root" data-quartier class="relative pt-1">
      <button
        id="quartier-select"
        type="button"
        class="flex min-h-12 w-full items-center justify-between gap-2 rounded-xl border-2 bg-white px-3.5 text-left text-base font-semibold shadow-sm"
        :class="invalid ? 'border-red-500 text-red-800 ring-4 ring-red-100' : 'border-orange-400 text-slate-500 ring-4 ring-orange-100'"
        :aria-expanded="listOpen"
        aria-haspopup="listbox"
        @click="toggleList"
      >
        <span class="min-w-0 truncate">{{ loading ? 'Chargement des quartiers…' : `Quel quartier à ${commune.name} ?` }}</span>
        <span class="shrink-0 text-lg leading-none" :class="invalid ? 'text-red-600' : 'text-orange-500'" aria-hidden="true">▾</span>
      </button>
      <p v-if="invalid" class="mt-1 text-sm font-semibold text-red-700" role="alert">Choisissez le quartier à {{ commune.name }}, ou « Toute la commune ».</p>
      <div v-if="listOpen" class="absolute inset-x-0 z-30 mt-1 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
        <div class="border-b border-slate-100 p-2">
          <input ref="searchInput" v-model="query" type="search" class="input py-2 text-base" :placeholder="`Chercher un quartier de ${commune.name}`" :aria-label="`Chercher un quartier de ${commune.name}`" autocomplete="off" />
        </div>
        <ul class="max-h-64 overflow-y-auto py-1" role="listbox" :aria-label="`Quartiers de ${commune.name}`">
          <li v-if="!query.trim()">
            <button type="button" class="flex w-full items-center justify-between px-3.5 py-2.5 text-left font-semibold text-emerald-800 hover:bg-emerald-50" role="option" @click="pick({ ...commune, label: commune.name }, [home?.id])">
              Toute la commune<span v-if="showFees" class="text-sm">{{ feeText(commune) }}</span>
            </button>
          </li>
          <li v-for="q in filtered" :key="q.id">
            <button type="button" class="flex w-full items-center justify-between gap-2 px-3.5 py-2.5 text-left hover:bg-slate-50" role="option" @click="pick(q, [commune.id, home?.id])">
              <span class="truncate">{{ q.name }}</span>
              <span v-if="showFees && q.fee !== commune.fee" class="shrink-0 text-sm" :class="q.learned ? 'font-semibold text-emerald-700' : 'text-slate-500'">{{ feeText(q) }}</span>
            </button>
          </li>
          <li v-if="query.trim() && !filtered.length" class="px-3.5 py-2.5 text-sm text-slate-600">Aucun quartier « {{ query.trim() }} » à {{ commune.name }}.</li>
          <li class="border-t border-slate-100">
            <button type="button" class="w-full px-3.5 py-2.5 text-left text-sm font-semibold text-slate-600 hover:bg-slate-50" @click="listOpen = false; emit('search')">+ Quartier pas dans la liste</button>
          </li>
        </ul>
      </div>
    </div>

    <!-- Expédition : villes -->
    <template v-if="cities && !commune">
      <p v-if="rates.expedition.fee !== null && showFees" class="text-xs text-slate-600">Prix convenu avec le client (livraison + expédition), à partir de {{ money(rates.expedition.fee) }}.</p>
      <div v-tab-strip class="-mx-4 flex gap-1.5 overflow-x-auto px-4 sm:-mx-5 sm:px-5" aria-label="Villes">
        <button v-for="c in rates.cities" :key="c.id" type="button" class="shrink-0 whitespace-nowrap rounded-full border border-slate-200 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:border-sky-400" @click="pick({ ...c, label: c.name }, [])">
          {{ c.name }}
        </button>
      </div>
    </template>
  </div>
</template>
