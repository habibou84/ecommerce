<script setup>
import { computed, reactive, ref } from 'vue'
import http, { errorMessage } from '../../lib/http'

/**
 * Article mystère : cocher un article (caché des clientes jusqu'à sa révélation pendant le live : écran, liste
 * envoyée, étiquette) et écrire jusqu'à 3 indices, donnés un par un pendant le jeu.
 */
const props = defineProps({
  live: { type: Object, required: true },
  dark: { type: Boolean, default: false },
})
const emit = defineEmits(['saved'])

const products = computed(() => {
  const seen = new Map()
  for (const i of props.live.items) {
    if (!seen.has(i.code)) seen.set(i.code, { code: i.code, title: i.product ?? i.title, image: i.image, mystery: !!i.mystery, revealed: !!i.revealed, hints: i.hints ?? [] })
  }
  return [...seen.values()]
})
const editing = reactive({ code: null, hints: ['', '', ''] })
const busy = ref(false)
const error = ref('')
function edit(p) {
  editing.code = p.code
  editing.hints = [...p.hints, '', '', ''].slice(0, 3)
}
async function save(p, mystery) {
  busy.value = true
  error.value = ''
  try {
    const hints = editing.code === p.code ? editing.hints : p.hints
    const { data } = await http.put(`/lives/${props.live.id}/mystery`, { code: p.code, mystery, hints })
    emit('saved', data.data)
    if (mystery) edit({ ...p, hints: hints.filter((h) => h.trim()) })
    else editing.code = null
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-2 text-sm" :class="dark ? 'text-white' : 'text-slate-900'">
    <p :class="dark ? 'text-slate-300' : 'text-slate-600'">Coche un article : il reste <b>caché</b> (écran, liste envoyée aux clientes, étiquette) jusqu'à ce que tu le révèles pendant le live. Les indices sont donnés un par un.</p>
    <div v-for="p in products" :key="p.code" class="rounded-xl p-2" :class="dark ? 'bg-white/10' : 'border border-slate-200 bg-white'">
      <div class="flex items-center gap-2">
        <span class="relative h-10 w-10 shrink-0 overflow-hidden rounded-lg bg-slate-200"><img v-if="p.image" :src="p.image" alt="" class="h-full w-full object-cover" :class="{ 'blur-[3px]': p.mystery && !p.revealed }"></span>
        <span class="min-w-0 flex-1"><b class="mr-1">{{ p.code }}</b><span class="truncate">{{ p.title }}</span></span>
        <span v-if="p.revealed" class="shrink-0 text-xs font-bold text-emerald-500">Révélé ✓</span>
        <button v-else type="button" class="shrink-0 rounded-full px-3 py-1.5 text-xs font-extrabold" :aria-pressed="p.mystery"
                :class="p.mystery ? 'bg-violet-600 text-white' : dark ? 'bg-white/15 text-white' : 'bg-slate-100 text-slate-700'" :disabled="busy" @click="save(p, !p.mystery)">
          {{ p.mystery ? '🎁 Mystère ✓' : '🎁 Mystère' }}
        </button>
      </div>
      <form v-if="p.mystery && !p.revealed" class="mt-2 space-y-1.5" @submit.prevent="save(p, true)">
        <template v-if="editing.code === p.code">
          <input v-for="k in 3" :key="k" v-model="editing.hints[k - 1]" class="input py-1.5 text-sm text-slate-900" maxlength="80" :placeholder="['Indice 1 (ex. Ça se porte au poignet)', 'Indice 2 (ex. C\'est doré)', 'Indice 3 (ex. Ça donne l\'heure)'][k - 1]" :aria-label="`Indice ${k}`">
          <button class="w-full rounded-lg bg-violet-600 py-2 text-xs font-extrabold text-white disabled:opacity-50" :disabled="busy">Enregistrer les indices</button>
        </template>
        <button v-else type="button" class="text-xs font-bold underline" @click="edit(p)">{{ p.hints.length ? `💡 ${p.hints.length} indice${p.hints.length > 1 ? 's' : ''} · modifier` : '💡 Ajouter des indices' }}</button>
      </form>
    </div>
    <p v-if="error" class="text-sm font-bold text-red-500">{{ error }}</p>
  </div>
</template>
