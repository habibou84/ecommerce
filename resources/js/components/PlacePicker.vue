<script setup>
import { ref, watch } from 'vue'
import http from '../lib/http'

// Choix du quartier ou de la commune de livraison, avec ajout d'un quartier absent du référentiel
const props = defineProps({
  modelValue: { type: Object, default: null }, // { id, label, ancestor_ids }
  id: { type: String, default: 'place' },
})
const emit = defineEmits(['update:modelValue'])

const search = ref(props.modelValue?.label ?? '')
const results = ref([])
const open = ref(false)
const adding = ref(false)
const communes = ref([])
const newPlace = ref({ name: '', parent_id: null })
let timer

watch(() => props.modelValue, (v) => {
  if (v?.label && v.label !== search.value) search.value = v.label
})

function onInput() {
  open.value = true
  if (props.modelValue) emit('update:modelValue', null)
  clearTimeout(timer)
  timer = setTimeout(async () => {
    if (search.value.trim().length < 2) return (results.value = [])
    results.value = (await http.get('/places', { params: { search: search.value } })).data.data
  }, 200)
}

function close() {
  setTimeout(() => (open.value = false), 150)
}

function choose(place) {
  emit('update:modelValue', place)
  search.value = place.label
  open.value = false
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
    <input :id="id" v-model="search" class="input" placeholder="Quartier ou commune (ex. Angré, Yop Siporex)" autocomplete="off" @input="onInput" @focus="open = !!results.length" @blur="close" />
    <ul v-if="open && (results.length || search.length >= 2)" class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
      <li v-for="place in results" :key="place.id">
        <button type="button" class="w-full px-3 py-2 text-left text-sm hover:bg-emerald-50" @mousedown.prevent="choose(place)">
          {{ place.label }} <span v-if="place.type === 'commune'" class="text-xs text-slate-400">commune</span>
        </button>
      </li>
      <li>
        <button type="button" class="w-full px-3 py-2 text-left text-sm text-emerald-700 hover:bg-emerald-50" @mousedown.prevent="startAdding">+ Ajouter « {{ search }} » comme quartier</button>
      </li>
    </ul>
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
