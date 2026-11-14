<script setup>
import { computed, onMounted, ref } from 'vue'
import http from '../lib/http'

/**
 * Choix de plusieurs lieux (ville, commune ou quartier) : la ville de la boutique et ses communes en un toucher, les
 * autres (quartiers, autres villes) par la recherche. Un lieu couvre tout ce qu'il contient : Cocody couvre Angré.
 */
const props = defineProps({
  modelValue: { type: Array, default: () => [] }, // [{ id, label }]
  id: { type: String, default: 'places' },
  emptyLabel: { type: String, default: 'Aucun lieu choisi : partout.' },
})
const emit = defineEmits(['update:modelValue'])

const home = ref([])
const search = ref('')
const results = ref([])
let timer

onMounted(async () => {
  try {
    home.value = (await http.get('/places', { params: { home: 1 } })).data.data
  } catch {
    home.value = []
  }
})

const chosenIds = computed(() => new Set(props.modelValue.map((p) => p.id)))
const isChosen = (p) => chosenIds.value.has(p.id)

function toggle(place) {
  emit('update:modelValue', isChosen(place)
    ? props.modelValue.filter((p) => p.id !== place.id)
    : [...props.modelValue, { id: place.id, label: place.label ?? place.name }])
}

function onInput() {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    if (search.value.trim().length < 2) return (results.value = [])
    results.value = (await http.get('/places', { params: { search: search.value } })).data.data
  }, 200)
}

function pick(place) {
  if (!isChosen(place)) toggle(place)
  search.value = ''
  results.value = []
}
</script>

<template>
  <div class="space-y-2 text-sm">
    <!-- Lieux choisis -->
    <div class="flex flex-wrap gap-1.5">
      <span v-for="p in modelValue" :key="p.id" class="inline-flex items-center gap-1 rounded-full bg-emerald-100 py-1 pl-3 pr-1 text-emerald-900">
        {{ p.label }}
        <button type="button" class="grid h-5 w-5 place-items-center rounded-full hover:bg-emerald-200" :aria-label="`Retirer ${p.label}`" @click="toggle(p)">×</button>
      </span>
      <span v-if="!modelValue.length" class="py-1 text-slate-500">{{ emptyLabel }}</span>
    </div>

    <!-- Ville de la boutique et ses communes -->
    <div v-if="home.length" class="flex flex-wrap gap-1.5">
      <button v-for="p in home.filter((h) => !isChosen(h))" :key="p.id" type="button"
              class="rounded-full border border-slate-200 px-3 py-1 hover:border-emerald-600 hover:text-emerald-700"
              @click="toggle(p)">+ {{ p.type === 'city' ? `Tout ${p.name}` : p.name }}</button>
    </div>

    <!-- Quartier ou autre ville -->
    <div class="relative">
      <input :id="id" v-model="search" type="search" class="input" placeholder="Chercher un quartier ou une autre ville…" autocomplete="off" @input="onInput" />
      <ul v-if="results.length" class="absolute z-10 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
        <li v-for="p in results" :key="p.id">
          <button type="button" class="w-full px-3 py-2 text-left hover:bg-slate-50" :class="{ 'text-slate-400': isChosen(p) }" @click="pick(p)">{{ p.label }}</button>
        </li>
      </ul>
    </div>
  </div>
</template>
