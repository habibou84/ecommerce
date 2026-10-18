<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import http from '../lib/http'
import { cachedGet, dismissFailed, flush, forgetAll, offline, startOfflineSync } from '../lib/courierOffline'
import { registerDevice, unregisterDevice } from '../lib/push'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()
const available = ref(true)
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
    })
  }
  try {
    available.value = (await cachedGet('/courier/me', 'me')).is_available
  } catch {
    // affiché par les pages
  }
})

async function toggle() {
  available.value = !available.value
  try {
    await http.patch('/courier/availability', { is_available: available.value })
  } catch {
    available.value = !available.value
  }
}

async function logout() {
  if (offline.pending && !confirm(`${offline.pending} étape(s) pas encore envoyée(s) seront perdues. Sortir quand même ?`)) return
  await unregisterDevice()
  await auth.logout().catch(() => {})
  await forgetAll()
  router.replace('/livreur/connexion')
}
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

    <main class="flex-1 px-4 pt-4 pb-24">
      <RouterView />
    </main>

    <nav class="fixed inset-x-0 bottom-0 z-20 mx-auto flex max-w-lg border-t border-slate-200 bg-white pb-[env(safe-area-inset-bottom)]">
      <RouterLink to="/livreur" class="flex-1 py-3 text-center text-sm" :class="$route.path === '/livreur' || $route.path.startsWith('/livreur/missions') ? 'font-semibold text-emerald-700' : 'text-slate-500'">📦 Livraisons</RouterLink>
      <RouterLink to="/livreur/argent" class="flex-1 py-3 text-center text-sm" :class="$route.path === '/livreur/argent' ? 'font-semibold text-emerald-700' : 'text-slate-500'">💰 Mon argent</RouterLink>
      <button class="flex-1 py-3 text-center text-sm text-slate-500" @click="logout">↩ Sortir</button>
    </nav>
  </div>
</template>
