<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import http, { errorMessage } from '../lib/http'

/**
 * Dans l'écran du live : bandeau « Afficher mes articles dans le live » avec l'état des écrans, les trois boutons
 * à toucher pendant le direct (article montré, défilement, « Comment commander », chacun avec sa vignette et le moment
 * où s'en servir) et l'accès à la page qui explique
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

// Ce que voient les clientes : vignette, phrase et moment où s'en servir
const MODES = [
  ['product', '🏷️', 'L\'article montré', 'Un seul article en grand, avec son code et son prix.', 'Quand vous présentez un article'],
  ['scroll', '🔁', 'Défilement', 'Tous les articles passent l\'un après l\'autre.', 'Quand vous répondez aux commentaires'],
  ['how', '🛒', 'Comment commander', 'Explique comment réserver, avec le QR code WhatsApp.', 'Quand de nouvelles personnes arrivent'],
]
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
      <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Ce que voient les clientes maintenant</p>
      <div class="space-y-2">
        <button v-for="[value, icon, label, text, when] in MODES" :key="value" type="button" class="flex w-full items-center gap-3 rounded-2xl border-2 p-2 text-left" :class="(live.display_mode ?? 'product') === value ? 'border-orange-600 bg-orange-50' : 'border-slate-200'" :aria-pressed="(live.display_mode ?? 'product') === value" @click="setMode(value)">
          <span class="vg" :class="`vg-${value}`" aria-hidden="true"><span /><span /><span /></span>
          <span class="min-w-0 flex-1 text-xs leading-snug text-slate-600">
            <b class="block text-sm text-slate-900">{{ icon }} {{ label }}</b>{{ text }}
            <span class="block font-extrabold text-orange-800">{{ when }}</span>
          </span>
        </button>
      </div>
      <p class="mt-2 text-center text-xs text-slate-500">Touchez « Montrer » sur un article : on revient tout seul à « L'article montré ».</p>
      <p v-if="error" class="mt-2 text-sm text-red-600">{{ error }}</p>
    </div>

    <RouterLink :to="`/gestion/live/${live.id}/afficher`" class="block bg-yellow-400 py-3 text-center text-sm font-black text-slate-900">{{ screens.length ? 'Changer de façon d\'afficher ›' : 'Choisir comment afficher ›' }}</RouterLink>
  </section>
</template>

<style scoped>
/* Vignettes de ce qui s'affiche */
.vg { position: relative; display: flex; gap: 3px; width: 64px; height: 44px; flex-shrink: 0; overflow: hidden; padding: 4px; border-radius: 8px; background: #020617; }
.vg span { border-radius: 3px; }
.vg-product span:nth-child(1) { width: 32px; background: linear-gradient(135deg, #f97316, #be123c); }
.vg-product span:nth-child(2) { position: absolute; right: 5px; top: 9px; width: 20px; height: 5px; background: #fff; }
.vg-product span:nth-child(3) { position: absolute; right: 5px; top: 18px; width: 15px; height: 5px; background: #facc15; }
.vg-scroll span { flex: 1; background: linear-gradient(135deg, #f97316, #be123c); }
.vg-scroll span:nth-child(2) { background: linear-gradient(135deg, #34d399, #0f766e); }
.vg-scroll span:nth-child(3) { background: linear-gradient(135deg, #a78bfa, #6d28d9); opacity: .6; }
.vg-how { align-items: center; justify-content: center; }
.vg-how span:nth-child(1) { width: 14px; height: 14px; background: #fff; }
.vg-how span:nth-child(2) { width: 22px; height: 4px; background: #16a34a; }
.vg-how span:nth-child(3) { display: none; }
</style>
