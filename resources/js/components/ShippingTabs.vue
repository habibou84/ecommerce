<script setup>
import { computed } from 'vue'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const tabs = computed(() => [
  ...(auth.can('shipments.manage') ? [{ to: '/gestion/expeditions', label: 'Expéditions', exact: true }, { to: '/gestion/expeditions/regles', label: 'Règles d\'affectation' }] : []),
  ...(auth.can('carriers.manage')
    ? [
        { to: '/gestion/expeditions/transporteurs', label: 'Transporteurs' },
        { to: '/gestion/expeditions/reversements', label: 'À recevoir' },
      ]
    : []),
])
</script>

<template>
  <nav class="-mx-4 flex gap-1 overflow-x-auto border-b border-slate-200 px-4 sm:mx-0 sm:px-0">
    <RouterLink
      v-for="tab in tabs"
      :key="tab.to"
      :to="tab.to"
      class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"
      :class="(tab.exact ? $route.path === tab.to : $route.path.startsWith(tab.to)) ? 'border-emerald-700 text-emerald-800' : 'border-transparent text-slate-600 hover:text-slate-900'"
    >
      {{ tab.label }}
    </RouterLink>
  </nav>
</template>
