<script setup>
import { useRoute } from 'vue-router'
import LiveScreenTabs from '../../../components/LiveScreenTabs.vue'

// Ancienne adresse « Afficher mes articles » : les mêmes onglets que sur la page du live
const props = defineProps({ id: { type: [String, Number], required: true } })
const route = useRoute()
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-4">
    <RouterLink :to="`/gestion/live/${props.id}`" class="inline-flex items-center gap-1 text-sm text-emerald-700 hover:underline">← Retour au live</RouterLink>
    <LiveScreenTabs :id="props.id" :initial="route.query.onglet === 'ecran' ? 'screen' : 'products'" @add="$router.push(`/gestion/live/${props.id}`)" />
  </div>
</template>
