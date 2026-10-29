<script setup>
// Interrupteur marche / arrêt (même dessin que les réglages des alertes)
defineProps({ modelValue: { type: Boolean, default: false }, label: { type: String, required: true }, disabled: { type: Boolean, default: false } })
const emit = defineEmits(['update:modelValue'])
</script>

<template>
  <button type="button" role="switch" :aria-checked="modelValue" :aria-label="label" :disabled="disabled"
          class="relative h-7 w-12 shrink-0 rounded-full transition disabled:opacity-50" :class="modelValue ? 'bg-emerald-600' : 'bg-slate-300'"
          @click="emit('update:modelValue', !modelValue)">
    <span class="absolute top-0.5 h-6 w-6 rounded-full bg-white shadow transition-all" :class="modelValue ? 'left-[22px]' : 'left-0.5'" />
  </button>
</template>
