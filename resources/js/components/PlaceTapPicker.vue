<script setup>
import { computed, ref } from 'vue'
import http from '../lib/http'
import { money } from '../lib/format'

// Lieu de livraison en touchant : les communes de la ville de la boutique (les plus livrées d'abord, avec leur prix),
// puis les quartiers de la commune ; « Expédition vers une autre ville » pour les autres villes.
const props = defineProps({
  rates: { type: Object, required: true }, // GET /delivery-rates
  showFees: { type: Boolean, default: true },
})
const emit = defineEmits(['pick', 'search'])

const SHOWN = 10
const all = ref(false)
const commune = ref(null) // { id, name, fee }
const quartiers = ref([])
const loading = ref(false)
const cities = ref(false)

const communes = computed(() => (all.value ? props.rates.places : props.rates.places.slice(0, SHOWN)))
const hidden = computed(() => Math.max(0, props.rates.places.length - SHOWN))
const home = computed(() => props.rates.home)

const feeText = (p) => (p.fee === null || p.fee === undefined ? 'prix à indiquer' : p.fee === 0 ? 'offerte' : money(p.fee))

async function open(p) {
  if (!p.has_children) return pick(p, [home.value?.id])
  commune.value = p
  loading.value = true
  try {
    quartiers.value = (await http.get(`/delivery-rates/places/${p.id}`)).data.data.places
  } finally {
    loading.value = false
  }
}

function pick(p, ancestors) {
  emit('pick', { id: p.id, label: p.label ?? p.name, ancestor_ids: ancestors.filter(Boolean) })
}

function back() {
  commune.value = null
  cities.value = false
}
</script>

<template>
  <div class="space-y-2">
    <!-- Expédition : villes -->
    <template v-if="cities">
      <div class="flex items-center gap-2 text-sm">
        <span class="rounded-full bg-sky-50 px-3 py-1 font-semibold text-sky-800">Expédition vers une autre ville</span>
        <button type="button" class="ml-auto font-medium text-emerald-700 underline" @click="back">Retour</button>
      </div>
      <p class="text-sm text-slate-600">
        Livraison jusqu'à la gare{{ rates.expedition.fee !== null && showFees ? ` : ${money(rates.expedition.fee)}` : '' }} ; les frais de la compagnie de transport sont connus au dépôt.
      </p>
      <div class="grid grid-cols-2 gap-1.5 sm:grid-cols-3">
        <button v-for="c in rates.cities" :key="c.id" type="button" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-left text-[15px] font-semibold hover:border-sky-400" @click="pick({ ...c, label: c.name }, [])">
          {{ c.name }}
        </button>
      </div>
    </template>

    <!-- Quartiers de la commune -->
    <template v-else-if="commune">
      <div class="flex items-center gap-2 text-sm">
        <span class="rounded-full bg-emerald-50 px-3 py-1 font-semibold text-emerald-800">{{ commune.name }}<template v-if="showFees"> · {{ feeText(commune) }}</template></span>
        <button type="button" class="ml-auto font-medium text-emerald-700 underline" @click="back">Changer de commune</button>
      </div>
      <p class="text-sm font-semibold text-slate-700">Quel quartier ?</p>
      <p v-if="loading" class="text-sm text-slate-400">Chargement…</p>
      <div v-else class="grid grid-cols-2 gap-1.5 sm:grid-cols-3">
        <button v-for="q in quartiers" :key="q.id" type="button" class="min-w-0 rounded-xl border border-slate-200 bg-white px-3 py-2 text-left hover:border-emerald-500" @click="pick(q, [commune.id, home?.id])">
          <span class="block truncate text-[15px] font-semibold">{{ q.name }}</span>
          <span v-if="showFees && q.fee !== commune.fee" class="block text-xs" :class="q.learned ? 'text-emerald-700' : 'text-slate-500'">{{ feeText(q) }}{{ q.learned ? ' · retenu' : '' }}</span>
        </button>
        <button type="button" class="col-span-2 rounded-xl border border-emerald-200 bg-emerald-50/50 px-3 py-2 text-center text-[15px] font-semibold text-emerald-800 sm:col-span-3" @click="pick({ ...commune, label: commune.name }, [home?.id])">Toute la commune</button>
        <button type="button" class="col-span-2 rounded-xl border border-dashed border-slate-300 px-3 py-2 text-center text-sm text-slate-600 sm:col-span-3" @click="emit('search')">+ Quartier pas dans la liste</button>
      </div>
    </template>

    <!-- Communes de la ville -->
    <template v-else>
      <p class="text-sm font-semibold text-slate-700">Ou touchez la commune</p>
      <div class="grid grid-cols-2 gap-1.5 sm:grid-cols-3">
        <button v-for="p in communes" :key="p.id" type="button" class="min-w-0 rounded-xl border border-slate-200 bg-white px-3 py-2 text-left hover:border-emerald-500" @click="open(p)">
          <span class="block truncate text-[15px] font-semibold">{{ p.name }}</span>
          <span v-if="showFees" class="block text-xs" :class="p.learned ? 'text-emerald-700' : 'text-slate-500'">{{ feeText(p) }}{{ p.learned ? ' · retenu' : '' }}</span>
        </button>
        <button v-if="hidden && !all" type="button" class="rounded-xl border border-dashed border-slate-300 px-3 py-2 text-left text-sm text-slate-600" @click="all = true">+ {{ hidden }} autre(s) commune(s)</button>
        <button v-if="rates.cities.length" type="button" class="rounded-xl border border-dashed border-sky-300 px-3 py-2 text-left text-sm font-medium text-sky-800" @click="cities = true">Expédition vers une autre ville…</button>
      </div>
    </template>
  </div>
</template>
