<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import http from '../lib/http'
import { registerDevice, unregisterDevice } from '../lib/push'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()
const available = ref(true)

onMounted(async () => {
  registerDevice()
  // Installable sur l'écran d'accueil ; la page s'ouvre même sans réseau
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('/livreur-sw.js').catch(() => {})
  try {
    available.value = (await http.get('/courier/me')).data.data.is_available
  } catch {
    // affiché par les pages
  }
})

async function toggle() {
  available.value = !available.value
  await http.patch('/courier/availability', { is_available: available.value })
}

async function logout() {
  await unregisterDevice()
  await auth.logout()
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
