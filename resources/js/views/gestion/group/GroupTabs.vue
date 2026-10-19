<script setup>
// Onglets de l'espace commun à plusieurs boutiques
const TABS = [
  ['/gestion/mes-boutiques', 'Boutiques'],
  ['/gestion/mes-boutiques/statistiques', 'Statistiques'],
  ['/gestion/mes-boutiques/caisse', 'Caisse'],
  ['/gestion/mes-boutiques/livreurs', 'Livreurs'],
]
</script>

<template>
  <nav v-tab-strip class="-mx-4 flex gap-1 overflow-x-auto border-b border-slate-200 px-4 text-sm sm:mx-0 sm:px-0">
    <RouterLink v-for="[to, label] in TABS" :key="to" :to="to" class="whitespace-nowrap border-b-2 px-3 py-2"
                :class="$route.path === to ? 'border-emerald-700 font-medium text-emerald-800' : 'border-transparent text-slate-600'">{{ label }}</RouterLink>
  </nav>
</template>
