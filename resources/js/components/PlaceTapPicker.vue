<script setup>
import { computed, ref } from 'vue'
import http from '../lib/http'
import { money } from '../lib/format'

// Lieu de livraison en touchant : les communes de la ville de la boutique (les plus livrées d'abord, avec leur prix)
// sur une ligne qu'on fait glisser (téléphone et ordinateur), puis les quartiers de la commune touchée sur une deuxième
// ligne ; « Autre ville » pour une expédition. Une ligne au lieu d'une grille : le formulaire reste court.
const props = defineProps({
  rates: { type: Object, required: true }, // GET /delivery-rates
  showFees: { type: Boolean, default: true },
})
const emit = defineEmits(['pick', 'search'])

const commune = ref(null) // { id, name, fee }
const quartiers = ref([])
const loading = ref(false)
const cities = ref(false)

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
              :class="cities ? 'border-sky-500 bg-sky-50 text-sky-900' : 'border-sky-300 text-sky-800'" :aria-pressed="cities" @click="commune = null; cities = !cities">📦 Autre ville</button>
    </div>

    <!-- Quartiers de la commune touchée -->
    <template v-if="commune">
      <p v-if="loading" class="text-sm text-slate-400">Chargement…</p>
      <div v-else v-tab-strip class="-mx-4 flex gap-1.5 overflow-x-auto px-4 sm:-mx-5 sm:px-5" :aria-label="`Quartiers de ${commune.name}`">
        <button v-for="q in quartiers" :key="q.id" type="button" class="shrink-0 whitespace-nowrap rounded-full border border-slate-200 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:border-emerald-500" @click="pick(q, [commune.id, home?.id])">
          {{ q.name }}<span v-if="showFees && q.fee !== commune.fee" class="ml-1 text-xs font-bold" :class="q.learned ? 'text-emerald-700' : 'text-slate-500'">{{ feeText(q) }}</span>
        </button>
        <button type="button" class="shrink-0 whitespace-nowrap rounded-full border border-emerald-200 bg-emerald-50/60 px-3 py-1.5 text-sm font-semibold text-emerald-800" @click="pick({ ...commune, label: commune.name }, [home?.id])">Toute la commune</button>
        <button type="button" class="shrink-0 whitespace-nowrap rounded-full border border-dashed border-field px-3 py-1.5 text-sm text-slate-600" @click="emit('search')">+ Pas dans la liste</button>
      </div>
    </template>

    <!-- Expédition : villes -->
    <template v-else-if="cities">
      <p v-if="rates.expedition.fee !== null && showFees" class="text-xs text-slate-600">Prix convenu avec le client (livraison + expédition), à partir de {{ money(rates.expedition.fee) }}.</p>
      <div v-tab-strip class="-mx-4 flex gap-1.5 overflow-x-auto px-4 sm:-mx-5 sm:px-5" aria-label="Villes">
        <button v-for="c in rates.cities" :key="c.id" type="button" class="shrink-0 whitespace-nowrap rounded-full border border-slate-200 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:border-sky-400" @click="pick({ ...c, label: c.name }, [])">
          {{ c.name }}
        </button>
      </div>
    </template>
  </div>
</template>
