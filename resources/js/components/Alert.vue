<script setup>
defineProps({ tone: { type: String, default: 'error' } })

const tones = {
  error: 'border-red-200 bg-red-50 text-red-800',
  success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
  info: 'border-sky-200 bg-sky-50 text-sky-800',
  warning: 'border-amber-200 bg-amber-50 text-amber-900',
}
</script>

<template>
  <div class="rounded-lg border px-3 py-2 text-sm" :class="tones[tone]" role="alert">
    <slot />
  </div>
</template>
