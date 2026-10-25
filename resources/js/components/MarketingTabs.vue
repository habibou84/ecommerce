<script setup>
const tabs = [
  { to: '/gestion/marketing', label: 'Promotions', exact: true },
  { to: '/gestion/marketing/reseaux', label: 'Réseaux sociaux' },
  { to: '/gestion/marketing/relances', label: 'Relances WhatsApp' },
  { to: '/gestion/marketing/avis', label: 'Avis clients' },
  { to: '/gestion/marketing/fidelite', label: 'Fidélité' },
  { to: '/gestion/marketing/suivi', label: 'Suivi publicitaire' },
]
</script>

<template>
  <nav v-tab-strip class="-mx-4 flex gap-1 overflow-x-auto border-b border-slate-200 px-4 sm:mx-0 sm:px-0">
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
