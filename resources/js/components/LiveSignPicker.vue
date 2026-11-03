<script setup>
import { computed, ref } from 'vue'
import Modal from './Modal.vue'

/**
 * Choisir la pancarte d'un article du live : la lettre (série), puis le numéro. Les pancartes déjà prises par un
 * autre article du live sont grisées ; « Autre » pour une pancarte au-delà de 20 (B35…).
 * used : { A1: 'Robe wax', … } (pancartes prises par les autres articles)
 */
const props = defineProps({
  title: { type: String, required: true },
  current: { type: String, default: '' },
  used: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['pick', 'close'])

const LETTERS = 'ABCDEFGH'.split('')
const letter = ref((props.current || 'A').charAt(0).toUpperCase())
const other = ref('')
const otherError = ref('')
const numbers = Array.from({ length: 20 }, (_, i) => i + 1)
const codes = computed(() => numbers.map((n) => `${letter.value}${n}`))

function pick(code) {
  if (props.used[code]) return
  emit('pick', code)
}
function pickOther() {
  const code = other.value.trim().toUpperCase()
  if (!/^[A-Z][1-9][0-9]?$/.test(code)) {
    otherError.value = 'Une lettre puis un nombre de 1 à 99 (B35…).'
    return
  }
  if (props.used[code]) {
    otherError.value = `${code} est déjà sur « ${props.used[code]} ».`
    return
  }
  emit('pick', code)
}
</script>

<template>
  <Modal :title="`Pancarte · ${title}`" @close="emit('close')">
    <p class="text-sm text-slate-600">La pancarte que vous montrerez à la caméra avec cet article. Les clientes écrivent ce code.</p>
    <div class="mt-3 flex flex-wrap gap-1.5" role="tablist" aria-label="Série">
      <button v-for="l in LETTERS" :key="l" type="button" role="tab" :aria-selected="letter === l" class="h-9 w-9 rounded-lg text-sm font-black"
              :class="letter === l ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'" @click="letter = l">{{ l }}</button>
    </div>
    <div class="mt-3 grid grid-cols-5 gap-1.5">
      <button v-for="c in codes" :key="c" type="button" :disabled="!!used[c]" :title="used[c] ? `Déjà sur « ${used[c]} »` : ''"
              class="rounded-lg py-2.5 text-sm font-black disabled:cursor-not-allowed"
              :class="c === current ? 'bg-red-600 text-white ring-4 ring-red-100' : used[c] ? 'bg-slate-800 text-slate-400' : 'border-2 border-slate-200 bg-white hover:border-red-600'"
              @click="pick(c)">{{ c }}</button>
    </div>
    <p class="mt-2 text-xs text-slate-500">En gris foncé : déjà sur un autre article de ce live.</p>
    <form class="mt-4 flex items-start gap-2" @submit.prevent="pickOther">
      <label class="min-w-0 flex-1 text-sm">
        <span class="sr-only">Autre pancarte</span>
        <input v-model="other" class="input w-full uppercase" maxlength="3" placeholder="Autre pancarte (B35…)" autocapitalize="characters" @input="otherError = ''" />
        <span v-if="otherError" class="mt-1 block text-xs text-red-600">{{ otherError }}</span>
      </label>
      <button class="btn-secondary shrink-0" :disabled="!other.trim()">Choisir</button>
    </form>
  </Modal>
</template>
