<script setup>
import { computed, ref, watch } from 'vue'
import Icon from './Icon.vue'
import http from '../lib/http'
import { money } from '../lib/format'

// Quartier de livraison. Entreprise de livraison choisie : seulement les quartiers qu'elle dessert, rangés par zone
// avec son tarif. Sinon les lieux de la boutique avec ses zones, et l'ajout d'un quartier absent du référentiel.
const props = defineProps({
  modelValue: { type: Object, default: null }, // { id, label, ancestor_ids }
  id: { type: String, default: 'place' },
  carrierId: { type: Number, default: null },
  carrierName: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue', 'change-deliverer'])

const search = ref(props.modelValue?.label ?? '')
const results = ref([])
const open = ref(false)
const loading = ref(false)
const adding = ref(false)
const communes = ref([])
const newPlace = ref({ name: '', parent_id: null })
let timer

watch(() => props.modelValue, (v) => {
  if (v?.label && v.label !== search.value) search.value = v.label
  if (!v && !open.value) search.value = ''
})
watch(() => props.carrierId, () => {
  results.value = []
  if (open.value) load()
})

async function load() {
  const term = props.modelValue && search.value === props.modelValue.label ? '' : search.value.trim()
  if (!props.carrierId && term.length < 2) return (results.value = [])
  loading.value = true
  try {
    results.value = (await http.get('/orders/delivery-options/places', { params: { search: term || null, carrier_connection_id: props.carrierId } })).data.data
  } finally {
    loading.value = false
  }
}

function onFocus() {
  open.value = true
  if (props.carrierId && !results.value.length) load()
}

function onInput() {
  open.value = true
  if (props.modelValue) emit('update:modelValue', null)
  clearTimeout(timer)
  timer = setTimeout(load, 200)
}

function close() {
  setTimeout(() => (open.value = false), 150)
}

function choose(place) {
  emit('update:modelValue', place)
  search.value = place.label
  open.value = false
}

// Résultats rangés par zone (prix de la zone en titre)
const groups = computed(() => {
  const byZone = new Map()
  for (const p of results.value) {
    const key = p.zone ?? ''
    if (!byZone.has(key)) byZone.set(key, { zone: p.zone, fee: p.fee, places: [] })
    byZone.get(key).places.push(p)
  }
  return [...byZone.values()].sort((a, b) => (a.zone === null) - (b.zone === null))
})

// Partie tapée en gras dans chaque résultat
const normalize = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
function parts(label) {
  const term = normalize(search.value.trim())
  const at = term ? normalize(label).indexOf(term) : -1
  return at < 0 ? [label, '', ''] : [label.slice(0, at), label.slice(at, at + term.length), label.slice(at + term.length)]
}

async function startAdding() {
  adding.value = true
  open.value = false
  newPlace.value = { name: search.value.split(',')[0].trim(), parent_id: null }
  if (!communes.value.length) communes.value = (await http.get('/places', { params: { type: 'commune' } })).data.data
}

async function add() {
  const { data } = await http.post('/places', newPlace.value)
  const commune = communes.value.find((c) => c.id === newPlace.value.parent_id)
  choose({ id: data.data.id, label: data.data.label, ancestor_ids: [newPlace.value.parent_id, commune?.parent_id].filter(Boolean) })
  adding.value = false
}
</script>

<template>
  <div class="relative">
    <div class="relative">
      <Icon name="search" :size="20" class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" />
      <input :id="id" v-model="search" class="input-lg pl-12" :placeholder="carrierId ? `Quartier desservi par ${carrierName}` : 'Quartier ou commune (ex. Angré, Yop Siporex)'" autocomplete="off" @input="onInput" @focus="onFocus" @blur="close" />
    </div>
    <div v-if="open && (results.length || loading || carrierId || search.trim().length >= 2)" class="absolute inset-x-0 top-full z-20 mt-2 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
      <p v-if="carrierId" class="border-b border-slate-100 bg-slate-50 px-4 py-2 text-sm text-slate-600">Zones de <strong>{{ carrierName }}</strong> · seuls les quartiers qu'elle dessert</p>
      <div class="max-h-80 overflow-y-auto">
        <p v-if="loading && !results.length" class="px-4 py-3 text-sm text-slate-500">Recherche…</p>
        <p v-else-if="!results.length && carrierId" class="px-4 py-3 text-sm text-slate-500">Aucun quartier desservi ne correspond.</p>
        <template v-for="g in groups" :key="g.zone ?? 'hors-zone'">
          <p class="flex items-center justify-between px-4 pb-1 pt-3 text-xs font-bold uppercase tracking-wide text-slate-500">
            <span>{{ g.zone ? `Zone ${g.zone}` : 'Hors de vos zones' }}</span><span v-if="g.fee !== null && g.fee !== undefined">{{ money(g.fee) }}</span>
          </p>
          <button v-for="p in g.places" :key="p.id" type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-emerald-50" :class="{ 'bg-emerald-50': modelValue?.id === p.id }" @mousedown.prevent="choose(p)">
            <Icon name="pin" :size="18" :class="modelValue?.id === p.id ? 'text-emerald-700' : 'text-slate-400'" />
            <span class="min-w-0 flex-1">{{ parts(p.label)[0] }}<strong>{{ parts(p.label)[1] }}</strong>{{ parts(p.label)[2] }}<span v-if="p.type === 'commune'" class="ml-1 text-xs text-slate-400">commune</span></span>
            <span v-if="p.fee !== null && p.fee !== undefined" class="text-sm text-slate-500 tabular-nums">{{ money(p.fee) }}</span>
          </button>
        </template>
      </div>
      <p v-if="carrierId" class="border-t border-slate-100 px-4 py-2.5 text-sm text-slate-500">
        Quartier absent ? {{ carrierName }} ne le dessert pas :
        <button type="button" class="font-medium text-emerald-700 hover:underline" @mousedown.prevent="open = false; emit('change-deliverer')">choisir un autre livreur</button>
      </p>
      <button v-else-if="search.trim().length >= 2" type="button" class="w-full border-t border-slate-100 px-4 py-2.5 text-left text-sm text-emerald-700 hover:bg-emerald-50" @mousedown.prevent="startAdding">+ Ajouter « {{ search }} » comme quartier</button>
    </div>
    <div v-if="adding" class="mt-2 flex flex-wrap gap-2 rounded-lg bg-slate-50 p-2">
      <input v-model="newPlace.name" class="input min-w-0 flex-1" placeholder="Nom du quartier" />
      <select v-model="newPlace.parent_id" class="input w-auto">
        <option :value="null" disabled>Commune…</option>
        <option v-for="c in communes" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
      <button type="button" class="btn-secondary" :disabled="!newPlace.name || !newPlace.parent_id" @click="add">Ajouter</button>
      <button type="button" class="btn-ghost" @click="adding = false">Annuler</button>
    </div>
  </div>
</template>
