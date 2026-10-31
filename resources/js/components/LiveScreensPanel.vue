<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import http, { errorMessage } from '../lib/http'

/**
 * Dans l'écran du live : bandeau « Afficher mes articles dans le live » avec l'état des écrans, les trois boutons
 * à toucher pendant le direct (article choisi, défilement, « Comment commander ») et l'accès à la page qui explique
 * chaque façon d'afficher.
 */
const props = defineProps({ live: { type: Object, required: true } })
const emit = defineEmits(['update'])

const screens = ref([])
const error = ref('')
let timer

async function load() {
  try {
    screens.value = (await http.get('/lives/screens')).data.data
  } catch {
    // affichage secondaire
  }
}
onMounted(() => {
  load()
  timer = setInterval(load, 15000)
})
onUnmounted(() => clearInterval(timer))

const online = computed(() => screens.value.filter((s) => s.online))
const state = computed(() => {
  if (online.value.length) return `● ${online.value.length} écran${online.value.length > 1 ? 's' : ''} allumé${online.value.length > 1 ? 's' : ''} · ${online.value.map((s) => s.name).join(', ')}`
  if (screens.value.length) return `${screens.value.length} écran${screens.value.length > 1 ? 's' : ''} relié${screens.value.length > 1 ? 's' : ''}, éteint${screens.value.length > 1 ? 's' : ''} pour l'instant`
  return 'Télé, ordinateur, partage d\'écran ou cartes : à vous de choisir'
})

const MODES = [['product', '🏷️', 'Article choisi'], ['scroll', '🔁', 'Défilement'], ['how', '🛒', 'Commander']]
async function setMode(mode) {
  error.value = ''
  try {
    emit('update', (await http.post(`/lives/${props.live.id}/display`, { mode })).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <section class="overflow-hidden rounded-[20px] border-2 border-slate-900 bg-slate-900 text-white">
    <RouterLink :to="`/gestion/live/${live.id}/afficher`" class="flex items-center gap-3 p-4">
      <span class="relative h-10 w-14 shrink-0 rounded-md border-[3px] border-slate-600 bg-gradient-to-br from-orange-500 to-rose-700">
        <span class="absolute left-0.5 top-0.5 rounded-sm border border-white bg-red-600 px-1 text-[9px] font-black leading-tight">A1</span>
      </span>
      <span class="min-w-0 flex-1">
        <b class="block text-base leading-tight">Afficher mes articles dans le live</b>
        <span class="mt-0.5 block text-xs font-semibold" :class="online.length ? 'text-emerald-300' : 'text-slate-300'">{{ state }}</span>
      </span>
    </RouterLink>

    <div v-if="live.status === 'live'" class="bg-white px-4 pb-4 pt-3 text-slate-900">
      <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Ce que voient les clientes</p>
      <div class="grid grid-cols-3 gap-2">
        <button v-for="[value, icon, label] in MODES" :key="value" type="button" class="rounded-2xl border-2 px-1 py-2.5 text-center text-xs font-extrabold" :class="(live.display_mode ?? 'product') === value ? 'border-orange-600 bg-orange-50 text-orange-800' : 'border-slate-200 text-slate-700'" @click="setMode(value)">
          <span class="mb-0.5 block text-xl">{{ icon }}</span>{{ label }}
        </button>
      </div>
      <p v-if="error" class="mt-2 text-sm text-red-600">{{ error }}</p>
    </div>

    <RouterLink :to="`/gestion/live/${live.id}/afficher`" class="block bg-yellow-400 py-3 text-center text-sm font-black text-slate-900">Choisir comment afficher ›</RouterLink>
  </section>
</template>
