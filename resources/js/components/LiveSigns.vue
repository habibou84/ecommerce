<script setup>
import { computed, ref } from 'vue'
import LiveSignPicker from './LiveSignPicker.vue'
import http, { errorMessage } from '../lib/http'
import { useAuthStore } from '../stores/auth'

/**
 * Pancartes du live (écran du live, avant et pendant) : la pancarte de chaque produit, à changer d'un geste (avant le
 * live, ou pendant tant que personne ne l'a réservé), l'impression des pancartes, la liste d'aide et les cartes à
 * afficher à l'écran (photo + code de chaque article, à télécharger).
 */
const props = defineProps({ live: { type: Object, required: true }, open: { type: Boolean, default: false } })
const emit = defineEmits(['updated'])
const auth = useAuthStore()
// Cartes à l'écran : masquables dans la console (« Affichage des articles dans le live »)
const cardsOn = computed(() => auth.profile?.features?.live_screens !== false)

// Un produit = une pancarte (ses tailles et couleurs la partagent)
const products = computed(() => {
  const seen = new Map()
  props.live.items.forEach((i) => seen.has(i.code) || seen.set(i.code, { id: i.id, code: i.code, title: i.product ?? i.title }))
  return [...seen.values()]
})
const signing = ref(null)
const error = ref('')
const busy = ref(false)
const used = computed(() => Object.fromEntries(products.value.filter((p) => p.code !== signing.value?.code).map((p) => [p.code, p.title])))

async function choose(code) {
  const product = signing.value
  signing.value = null
  if (code === product.code) return
  busy.value = true
  error.value = ''
  try {
    emit('updated', (await http.post(`/lives/${props.live.id}/items/${product.id}/code`, { code })).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <details class="card p-4" :open="open">
    <summary class="flex cursor-pointer items-center gap-3">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl border-2 border-slate-900 bg-white text-sm font-black">A1</span>
      <span class="min-w-0 flex-1"><b class="block">🖨 Supports à imprimer</b><span class="text-sm text-slate-500">Pancartes à montrer à la caméra (le code de chaque article), liste d'aide et cartes avec la photo</span></span>
    </summary>
    <p v-if="error" class="mt-3 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
    <ul class="mt-3 grid min-w-0 grid-cols-1 gap-2 sm:grid-cols-2">
      <li v-for="p in products" :key="p.code" class="min-w-0">
        <button type="button" class="flex w-full items-center gap-3 rounded-xl border border-slate-200 p-2 text-left hover:border-red-600 disabled:opacity-60" :disabled="busy" @click="signing = p">
          <span class="grid h-10 w-12 shrink-0 place-items-center rounded-lg border-2 border-slate-900 font-black">{{ p.code }}</span>
          <span class="min-w-0 flex-1 truncate text-sm font-semibold">{{ p.title }}</span>
          <span class="shrink-0 text-xs font-semibold text-emerald-700">Changer</span>
        </button>
      </li>
    </ul>
    <p class="mt-2 text-xs text-slate-500">{{ live.status === 'live' ? 'Pendant le live, la pancarte d\'un article déjà réservé ne change plus.' : 'Changez la pancarte si vous en avez déjà une autre sous la main.' }}</p>
    <div class="mt-3 grid gap-2" :class="cardsOn ? 'sm:grid-cols-3' : 'sm:grid-cols-2'">
      <RouterLink :to="`/gestion/live/${live.id}/pancartes`" class="btn-secondary justify-center">🖨 Imprimer les pancartes</RouterLink>
      <RouterLink :to="`/gestion/live/${live.id}/pancartes?vue=liste`" class="btn-secondary justify-center">📋 Liste d'aide</RouterLink>
      <RouterLink v-if="cardsOn" :to="`/gestion/live/${live.id}/cartes`" class="btn-secondary justify-center">🖼 Cartes à l'écran</RouterLink>
    </div>
    <p v-if="cardsOn" class="mt-2 text-xs text-slate-500">Cartes à l'écran : une image par article (photo, code en grand, prix), à télécharger puis à afficher dans le live (TikTok, LIVE Studio, télé).</p>
    <LiveSignPicker v-if="signing" :title="signing.title" :current="signing.code" :used="used" @pick="choose" @close="signing = null" />
  </details>
</template>
