<script setup>
import { useRoute } from 'vue-router'
import CashDrawer from '../../../components/CashDrawer.vue'
import CashTabs from '../../../components/CashTabs.vue'

// Caisse › Tiroir-caisse : ouvrir, dépenses, retraits et apports, clôturer
const route = useRoute()
</script>

<template>
  <div class="space-y-4">
    <CashTabs />
    <CashDrawer :heading="false" :start="String(route.query.faire ?? '')" />
  </div>
</template>
