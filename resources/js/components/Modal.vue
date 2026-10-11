<script setup>
import { onBeforeUnmount, onMounted } from 'vue'

defineProps({ title: { type: String, required: true }, wide: { type: Boolean, default: false } })
const emit = defineEmits(['close'])

function onKey(e) {
  if (e.key === 'Escape') emit('close')
}
onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/40 sm:items-center sm:p-4" @click.self="emit('close')">
      <div class="max-h-[92vh] w-full overflow-y-auto rounded-t-2xl bg-white shadow-xl sm:rounded-2xl" :class="wide ? 'sm:max-w-3xl' : 'sm:max-w-lg'" role="dialog" aria-modal="true">
        <div class="sticky top-0 flex items-center justify-between border-b border-slate-100 bg-white px-5 py-4">
          <h2 class="font-semibold">{{ title }}</h2>
          <button class="btn-ghost -mr-2 px-2 py-1" aria-label="Fermer" @click="emit('close')">✕</button>
        </div>
        <div class="p-5">
          <slot />
        </div>
      </div>
    </div>
  </Teleport>
</template>
