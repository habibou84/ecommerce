<script setup>
// « En savoir plus » replié : les détails d'une explication (étapes, cas particuliers), ouverts au besoin
defineProps({ label: { type: String, default: 'En savoir plus' } })
</script>

<template>
  <details class="group mt-1.5">
    <summary class="inline-flex cursor-pointer list-none items-center gap-1 text-sm font-semibold text-emerald-700 [&::-webkit-details-marker]:hidden">
      {{ label }}<span class="transition group-open:rotate-180" aria-hidden="true">⌄</span>
    </summary>
    <div class="explain-detail mt-1 space-y-1"><slot /></div>
  </details>
</template>
