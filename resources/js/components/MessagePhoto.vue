<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import http from '../lib/http'

/**
 * Photo d'un message de Conversations : adresse publique (photo envoyée par la boutique), ou photo reçue du client,
 * lue à la demande (elle passe par la session de la boutique). Touchée : ouverte en grand dans un nouvel onglet.
 */
const props = defineProps({
  src: { type: String, default: null }, // adresse publique
  path: { type: String, default: null }, // photo reçue : chemin de l'API
  alt: { type: String, default: 'Photo' },
})

const url = ref(props.src)
const failed = ref(false)
let blobUrl = null

onMounted(async () => {
  if (props.src || !props.path) return
  try {
    const { data } = await http.get(props.path, { responseType: 'blob' })
    blobUrl = URL.createObjectURL(data)
    url.value = blobUrl
  } catch {
    failed.value = true
  }
})
onBeforeUnmount(() => blobUrl && URL.revokeObjectURL(blobUrl))
</script>

<template>
  <a v-if="url && !failed" :href="url" target="_blank" rel="noopener" class="mb-1 block" @click.stop>
    <img :src="url" :alt="alt" loading="lazy" class="max-h-64 w-full max-w-60 rounded-xl bg-slate-100 object-cover" @error="failed = true">
  </a>
  <span v-else-if="failed" class="mb-1 block rounded-xl bg-slate-100 px-3 py-2 text-xs text-slate-500">Photo plus disponible</span>
  <span v-else class="mb-1 block h-32 w-48 animate-pulse rounded-xl bg-slate-100" aria-label="Chargement de la photo" />
</template>
