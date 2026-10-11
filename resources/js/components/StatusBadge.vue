<script setup>
defineProps({ status: { type: String, required: true }, label: { type: String, required: true } })

const tones = {
  active: 'bg-emerald-100 text-emerald-800',
  draft: 'bg-slate-100 text-slate-700',
  archived: 'bg-amber-100 text-amber-900',
  in_transit: 'bg-sky-100 text-sky-800',
  received: 'bg-emerald-100 text-emerald-800',
  completed: 'bg-emerald-100 text-emerald-800',
  in_progress: 'bg-sky-100 text-sky-800',
  cancelled: 'bg-slate-100 text-slate-600',
}
</script>

<template>
  <span class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="tones[status] ?? 'bg-slate-100 text-slate-700'">{{ label }}</span>
</template>
