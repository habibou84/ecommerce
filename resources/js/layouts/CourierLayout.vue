<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue'
import http from '../lib/http'
import { cachedGet, dismissFailed, flush, offline, startOfflineSync } from '../lib/courierOffline'
import { registerDevice } from '../lib/push'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const available = ref(true)
const tracking = ref(false) // la boutique voit sa position pendant la tournée
const savedAt = (at) => new Date(at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })

onMounted(async () => {
  registerDevice()
  startOfflineSync()
  // Installable sur l'écran d'accueil ; la page s'ouvre même sans réseau. Les écrans de l'application sont chargés
  // tout de suite pour qu'ils soient gardés sur le téléphone avant de perdre le réseau.
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/livreur-sw.js').catch(() => {})
    navigator.serviceWorker.ready.then(() => {
      import('../views/courier/Missions.vue')
      import('../views/courier/Mission.vue')
      import('../views/courier/Money.vue')
      import('../views/courier/Account.vue')
    })
  }
  try {
    const me = await cachedGet('/courier/me', 'me')
    available.value = me.is_available
    tracking.value = me.tracking
  } catch {
    // affiché par les pages
  }
})

// Position partagée avec la boutique (si elle l'a activé) tant que le livreur est disponible, toutes les deux minutes
// au plus, et seulement application ouverte
let watcher = null
let lastSent = 0
function stopSharing() {
  if (watcher !== null) navigator.geolocation.clearWatch(watcher)
  watcher = null
}
watch([tracking, available], ([on, free]) => {
  stopSharing()
  if (!on || !free || !navigator.geolocation) return
  watcher = navigator.geolocation.watchPosition(({ coords }) => {
    if (Date.now() - lastSent < 120000 || !offline.online) return
    lastSent = Date.now()
    http.post('/courier/location', { latitude: coords.latitude, longitude: coords.longitude }).catch(() => {})
  }, () => {}, { enableHighAccuracy: false, maximumAge: 60000 })
})
onUnmounted(stopSharing)

async function toggle() {
  available.value = !available.value
  try {
    await http.patch('/courier/availability', { is_available: available.value })
  } catch {
    available.value = !available.value
  }
}

const TABS = [
  { to: '/livreur', icon: '📦', label: 'Ma tournée', active: (path) => path === '/livreur' || path.startsWith('/livreur/missions') },
  { to: '/livreur/caisse', icon: '💰', label: 'Ma caisse', active: (path) => path === '/livreur/caisse' },
  { to: '/livreur/compte', icon: '👤', label: 'Mon compte', active: (path) => path === '/livreur/compte' },
]
</script>

<template>
  <div class="mx-auto flex min-h-screen max-w-lg flex-col bg-slate-50">
    <header class="sticky top-0 z-20 flex items-center gap-3 bg-emerald-700 px-4 py-3 text-white">
      <div class="min-w-0 flex-1">
        <p class="truncate font-semibold">{{ auth.store?.name }}</p>
        <p class="truncate text-xs text-emerald-100">{{ auth.profile?.courier?.name }}</p>
      </div>
      <button class="rounded-full px-3 py-1.5 text-sm font-medium" :class="available ? 'bg-white text-emerald-800' : 'bg-emerald-900 text-emerald-100'" @click="toggle">
        {{ available ? '● Disponible' : '○ Indisponible' }}
      </button>
    </header>

    <div v-if="!offline.online || offline.pending || offline.failed.length" class="space-y-1 px-4 pt-3 text-sm">
      <p v-if="!offline.online" class="rounded-lg bg-slate-800 px-3 py-2 text-white">
        📵 Pas de réseau<template v-if="offline.savedAt"> · données de {{ savedAt(offline.savedAt) }}</template>. Continuez à livrer : tout est gardé sur le téléphone.
      </p>
      <p v-if="offline.pending" class="flex items-center gap-2 rounded-lg bg-amber-100 px-3 py-2 text-amber-900">
        <span class="flex-1">⏳ {{ offline.pending }} étape(s) en attente, envoyée(s) dès que le réseau revient.</span>
        <button class="font-semibold underline" :disabled="offline.syncing" @click="flush">{{ offline.syncing ? 'Envoi…' : 'Envoyer' }}</button>
      </p>
      <p v-for="f in offline.failed" :key="f.id" class="flex items-start gap-2 rounded-lg bg-red-100 px-3 py-2 text-red-900">
        <span class="flex-1">✕ {{ f.reference }} · « {{ f.label }} » refusé : {{ f.error }}</span>
        <button class="font-semibold underline" @click="dismissFailed(f.id)">OK</button>
      </p>
    </div>

    <p v-if="tracking && available" class="px-4 pt-2 text-xs text-slate-500">📍 Votre position est partagée avec la boutique pendant votre tournée.</p>

    <main class="flex-1 px-4 pt-4 pb-24">
      <RouterView />
    </main>

    <!-- Menu du bas : la sortie du compte est dans « Mon compte », jamais à portée d'un appui par erreur -->
    <nav class="fixed inset-x-0 bottom-0 z-20 mx-auto flex max-w-lg border-t border-slate-200 bg-white pb-[env(safe-area-inset-bottom)]">
      <RouterLink v-for="tab in TABS" :key="tab.to" :to="tab.to" class="flex flex-1 flex-col items-center gap-0.5 py-2 text-xs"
                  :class="tab.active($route.path) ? 'font-semibold text-emerald-700' : 'text-slate-500'">
        <span class="text-xl leading-none">{{ tab.icon }}</span>{{ tab.label }}
      </RouterLink>
    </nav>
  </div>
</template>
