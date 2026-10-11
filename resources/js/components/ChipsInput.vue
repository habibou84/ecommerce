<script setup>
import { ref } from 'vue'

// Saisie de valeurs multiples (valeurs d'option, étiquettes) : Entrée ou virgule pour ajouter
const props = defineProps({
  modelValue: { type: Array, required: true },
  placeholder: { type: String, default: '' },
  suggestions: { type: Array, default: () => [] },
  id: { type: String, default: undefined },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
const text = ref('')

function add(raw = text.value) {
  const values = raw.split(',').map((v) => v.trim()).filter(Boolean)
  const next = [...props.modelValue]
  for (const value of values) {
    if (!next.some((v) => v.toLowerCase() === value.toLowerCase())) next.push(value)
  }
  emit('update:modelValue', next)
  text.value = ''
}

function remove(index) {
  emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
}

function onKey(e) {
  if (e.key === 'Enter' || e.key === ',') {
    e.preventDefault()
    add()
  } else if (e.key === 'Backspace' && !text.value && props.modelValue.length) {
    remove(props.modelValue.length - 1)
  }
}
</script>

<template>
  <div>
    <div class="flex min-h-[2.75rem] flex-wrap items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-2 py-1.5 focus-within:border-emerald-600 focus-within:ring-2 focus-within:ring-emerald-600/20">
      <span v-for="(value, index) in modelValue" :key="value" class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-1 text-sm">
        {{ value }}
        <button v-if="!disabled" type="button" class="text-slate-400 hover:text-red-600" :aria-label="`Retirer ${value}`" @click="remove(index)">✕</button>
      </span>
      <input
        :id="id"
        v-model="text"
        class="min-w-[8rem] flex-1 border-0 bg-transparent px-1 py-1 text-base outline-none sm:text-sm"
        :placeholder="modelValue.length ? '' : placeholder"
        :disabled="disabled"
        @keydown="onKey"
        @blur="text && add()"
      />
    </div>
    <div v-if="suggestions.length && !disabled" class="mt-1.5 flex flex-wrap gap-1">
      <button
        v-for="s in suggestions.filter((s) => !modelValue.some((v) => v.toLowerCase() === s.toLowerCase())).slice(0, 8)"
        :key="s"
        type="button"
        class="rounded-md border border-dashed border-slate-300 px-2 py-0.5 text-xs text-slate-600 hover:border-emerald-600 hover:text-emerald-700"
        @click="add(s)"
      >
        + {{ s }}
      </button>
    </div>
  </div>
</template>
