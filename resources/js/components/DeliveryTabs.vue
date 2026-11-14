<script setup>
import { computed } from 'vue'
import { useAuthStore } from '../stores/auth'

// Menu « Livraisons » : qui livre, comment et pour combien (paie des livreurs). L'argent encaissé par les livreurs
// et les entreprises reste dans la Caisse.
const auth = useAuthStore()
const tabs = computed(() => [
  ...(auth.can('shipments.manage') ? [{ to: '/gestion/livraisons', label: 'En cours', exact: true }] : []),
  ...(auth.can('couriers.manage') ? [{ to: '/gestion/livraisons/livreurs', label: 'Mes livreurs', short: 'Livreurs' }] : []),
  ...(auth.can('carriers.manage') ? [{ to: '/gestion/livraisons/entreprises', label: 'Entreprises de livraison', short: 'Entreprises' }] : []),
  ...(auth.can('shipments.manage') ? [{ to: '/gestion/livraisons/automatisation', label: 'Automatisation' }] : []),
  ...(auth.can('couriers.manage') ? [{ to: '/gestion/livraisons/paie', label: 'Paie des livreurs', short: 'Paie' }] : []),
  ...(auth.can('store.manage') ? [{ to: '/gestion/livraisons/tarifs', label: 'Tarifs et zones', short: 'Tarifs', also: ['/gestion/livraisons/zones'] }] : []),
  ...(auth.can('couriers.manage') ? [{ to: '/gestion/livraisons/reglages', label: 'Réglages' }] : []),
])
</script>

<template>
  <nav v-tab-strip class="-mx-4 flex gap-1 overflow-x-auto border-b border-slate-200 px-4 sm:mx-0 sm:px-0">
    <RouterLink
      v-for="tab in tabs"
      :key="tab.to"
      :to="tab.to"
      class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"
      :class="(tab.exact ? $route.path === tab.to : [tab.to, ...(tab.also ?? [])].some((p) => $route.path.startsWith(p))) ? 'border-emerald-700 text-emerald-800' : 'border-transparent text-slate-600 hover:text-slate-900'"
    >
      <span class="sm:hidden">{{ tab.short ?? tab.label }}</span><span class="hidden sm:inline">{{ tab.label }}</span>
    </RouterLink>
  </nav>
</template>
