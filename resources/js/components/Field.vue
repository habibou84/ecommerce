<script setup>
defineProps({
  label: { type: String, required: true },
  error: { type: String, default: '' },
  hint: { type: String, default: '' },
  id: { type: String, required: true },
})
</script>

<template>
  <div>
    <label :for="id" class="label">{{ label }}</label>
    <slot />
    <p v-if="error" class="mt-1 text-sm text-red-600">{{ error }}</p>
    <p v-else-if="hint" class="mt-1 text-sm text-slate-500">{{ hint }}</p>
  </div>
</template>
