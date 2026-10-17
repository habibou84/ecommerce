<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import http from '../lib/http'
import { formatDateTime } from '../lib/format'

// Notifications internes : vérifiées toutes les minutes (le temps réel arrivera avec Reverb)
const router = useRouter()
const items = ref([])
const unread = ref(0)
const open = ref(false)
let timer

async function load() {
  try {
    const { data } = await http.get('/notifications')
    items.value = data.data
    unread.value = data.unread
  } catch {
    // réseau absent : on réessaiera
  }
}

onMounted(() => {
  load()
  timer = setInterval(load, 60000)
})
onBeforeUnmount(() => clearInterval(timer))

async function go(item) {
  open.value = false
  if (!item.read) {
    await http.post('/notifications/read', { ids: [item.id] })
    await load()
  }
  router.push(item.url)
}

async function readAll() {
  await http.post('/notifications/read')
  await load()
}
</script>

<template>
  <div class="relative">
    <button class="btn-ghost relative px-2" aria-label="Notifications" @click="open = !open">
      <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0" /></svg>
      <span v-if="unread" class="absolute -right-0.5 -top-0.5 grid h-5 min-w-5 place-items-center rounded-full bg-red-600 px-1 text-[11px] font-semibold text-white">{{ unread > 9 ? '9+' : unread }}</span>
    </button>
    <div v-if="open" class="fixed inset-0 z-30" @click="open = false" />
    <!-- Téléphone : toute la largeur de l'écran (la cloche n'est pas au bord, le panneau débordait à gauche) -->
    <div v-if="open" class="fixed inset-x-3 top-16 z-40 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg sm:absolute sm:inset-x-auto sm:right-0 sm:top-auto sm:mt-2 sm:w-80">
      <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2">
        <span class="font-semibold">Notifications</span>
        <button v-if="unread" class="text-xs text-emerald-700 hover:underline" @click="readAll">Tout marquer comme lu</button>
      </div>
      <ul class="max-h-96 divide-y divide-slate-100 overflow-y-auto">
        <li v-for="n in items" :key="n.id">
          <button class="w-full px-4 py-3 text-left text-sm hover:bg-slate-50" :class="{ 'bg-emerald-50/60': !n.read }" @click="go(n)">
            <span class="block font-medium">{{ n.title }}</span>
            <span class="block text-slate-600">{{ n.body }}</span>
            <span class="block text-xs text-slate-400">{{ formatDateTime(n.created_at) }}</span>
          </button>
        </li>
        <li v-if="!items.length" class="px-4 py-6 text-center text-sm text-slate-500">Aucune notification.</li>
      </ul>
    </div>
  </div>
</template>
