<script setup>
defineProps({ meta: { type: Object, default: null } })
const emit = defineEmits(['page'])
</script>

<template>
  <div v-if="meta && meta.last_page > 1" class="flex items-center justify-between gap-3 text-sm">
    <button class="btn-secondary px-3 py-1.5" :disabled="meta.current_page <= 1" @click="emit('page', meta.current_page - 1)">← Précédent</button>
    <span class="text-slate-500">Page {{ meta.current_page }} / {{ meta.last_page }} · {{ meta.total }} résultat{{ meta.total > 1 ? 's' : '' }}</span>
    <button class="btn-secondary px-3 py-1.5" :disabled="meta.current_page >= meta.last_page" @click="emit('page', meta.current_page + 1)">Suivant →</button>
  </div>
</template>
