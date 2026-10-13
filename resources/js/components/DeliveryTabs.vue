<script setup>
import { computed } from 'vue'
import { useAuthStore } from '../stores/auth'

// Menu « Livraisons » : qui livre et comment. L'argent (livreurs, entreprises) est dans la Caisse.
const auth = useAuthStore()
const tabs = computed(() => [
  ...(auth.can('shipments.manage') ? [{ to: '/gestion/livraisons', label: 'En cours', exact: true }] : []),
  ...(auth.can('couriers.manage') ? [{ to: '/gestion/livraisons/livreurs', label: 'Mes livreurs' }] : []),
  ...(auth.can('carriers.manage') ? [{ to: '/gestion/livraisons/entreprises', label: 'Entreprises de livraison' }] : []),
  ...(auth.can('store.manage') ? [{ to: '/gestion/livraisons/zones', label: 'Zones et tarifs' }] : []),
  ...(auth.can('shipments.manage') ? [{ to: '/gestion/livraisons/qui-livre-quoi', label: 'Qui livre quoi' }] : []),
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
