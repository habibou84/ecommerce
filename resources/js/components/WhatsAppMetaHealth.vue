<script setup>
import { computed, ref } from 'vue'
import http, { errorMessage } from '../lib/http'

/**
 * « État chez Meta » du numéro officiel : une ligne par point vérifié (compte, nom, paiement, qualité, vérification),
 * ce qui bloque d'abord, avec le bouton qui ouvre la bonne page de Meta. « Actualiser » relit tout de suite.
 */
const props = defineProps({ meta: { type: Object, required: true } })
const emit = defineEmits(['update'])

const busy = ref(false)
const error = ref('')
const BADGE = {
  ok: 'bg-emerald-100 text-emerald-800',
  partial: 'bg-amber-100 text-amber-900',
  blocked: 'bg-red-100 text-red-800',
  unknown: 'bg-slate-100 text-slate-600',
}
const ICON = { ok: '✅', warn: '⚠️', bad: '⛔', info: 'ℹ️' }

// « il y a 12 min », « il y a 3 h »
const checked = computed(() => {
  if (!props.meta.checked_at) return 'Pas encore vérifié chez Meta : la première vérification se fait dans les minutes qui suivent la connexion.'
  const minutes = Math.max(0, Math.round((Date.now() - new Date(props.meta.checked_at)) / 60000))
  return minutes < 1 ? 'Vérifié à l\'instant' : minutes < 60 ? `Vérifié il y a ${minutes} min` : `Vérifié il y a ${Math.round(minutes / 60)} h`
})

async function refresh() {
  busy.value = true
  error.value = ''
  try {
    emit('update', (await http.post('/whatsapp/health')).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="card p-4 sm:p-5">
    <div class="flex items-center gap-2">
      <h3 class="flex-1 text-[17px] font-extrabold">État chez Meta</h3>
      <span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="BADGE[meta.state] ?? BADGE.unknown">{{ meta.title }}</span>
    </div>
    <ul class="mt-2 divide-y divide-slate-100">
      <li v-for="l in meta.lines" :key="l.key" class="flex gap-3 py-2.5 text-sm">
        <span class="w-5 shrink-0 text-base leading-5" aria-hidden="true">{{ ICON[l.tone] }}</span>
        <span class="min-w-0 flex-1">
          <span class="block font-semibold" :class="{ 'text-red-800': l.tone === 'bad', 'text-amber-900': l.tone === 'warn' }">{{ l.title }}</span>
          <span v-if="l.detail" class="block text-xs text-slate-600">{{ l.detail }}</span>
          <a v-if="l.action" :href="l.action.url" target="_blank" rel="noopener" class="mt-1.5 inline-block rounded-lg px-3 py-1.5 text-xs font-bold"
             :class="l.tone === 'bad' || l.tone === 'warn' ? 'bg-emerald-700 text-white' : 'bg-slate-100 text-slate-800'">{{ l.action.label }} ↗</a>
        </span>
      </li>
    </ul>
    <p v-if="error" class="mt-2 text-sm text-red-700">{{ error }}</p>
    <div class="mt-2 flex items-center justify-between gap-3 text-xs text-slate-500">
      <span>{{ checked }}</span>
      <button type="button" class="font-semibold text-emerald-700" :disabled="busy" @click="refresh">{{ busy ? 'Vérification…' : 'Actualiser' }}</button>
    </div>
  </section>
</template>
