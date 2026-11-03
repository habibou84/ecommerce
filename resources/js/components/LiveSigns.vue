<script setup>
import { computed, ref } from 'vue'
import LiveSignPicker from './LiveSignPicker.vue'
import http, { errorMessage } from '../lib/http'

/**
 * Pancartes du live (écran du live, avant et pendant) : la pancarte de chaque produit, à changer d'un geste (avant le
 * live, ou pendant tant que personne ne l'a réservé), l'impression des pancartes et la liste d'aide.
 */
const props = defineProps({ live: { type: Object, required: true } })
const emit = defineEmits(['updated'])

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
  <details class="card p-4">
    <summary class="flex cursor-pointer items-center gap-3">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl border-2 border-slate-900 bg-white text-sm font-black">A1</span>
      <span class="min-w-0 flex-1"><b class="block">Pancartes à montrer à la caméra</b><span class="text-sm text-slate-500">Le code de chaque article, à imprimer et à tenir près de l'article</span></span>
    </summary>
    <p v-if="error" class="mt-3 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
    <ul class="mt-3 grid gap-2 sm:grid-cols-2">
      <li v-for="p in products" :key="p.code">
        <button type="button" class="flex w-full items-center gap-3 rounded-xl border border-slate-200 p-2 text-left hover:border-red-600 disabled:opacity-60" :disabled="busy" @click="signing = p">
          <span class="grid h-10 w-12 shrink-0 place-items-center rounded-lg border-2 border-slate-900 font-black">{{ p.code }}</span>
          <span class="min-w-0 flex-1 truncate text-sm font-semibold">{{ p.title }}</span>
          <span class="shrink-0 text-xs font-semibold text-emerald-700">Changer</span>
        </button>
      </li>
    </ul>
    <p class="mt-2 text-xs text-slate-500">{{ live.status === 'live' ? 'Pendant le live, la pancarte d\'un article déjà réservé ne change plus.' : 'Changez la pancarte si vous en avez déjà une autre sous la main.' }}</p>
    <div class="mt-3 grid gap-2 sm:grid-cols-2">
      <RouterLink :to="`/gestion/live/${live.id}/pancartes`" class="btn-secondary justify-center">🖨 Imprimer les pancartes</RouterLink>
      <RouterLink :to="`/gestion/live/${live.id}/pancartes?vue=liste`" class="btn-secondary justify-center">📋 Liste d'aide</RouterLink>
    </div>
    <LiveSignPicker v-if="signing" :title="signing.title" :current="signing.code" :used="used" @pick="choose" @close="signing = null" />
  </details>
</template>
