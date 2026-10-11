<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { errorMessage } from '../lib/http'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const menuOpen = ref(false)
const switchError = ref('')

// Modules à venir : affichés pour montrer la suite, sans lien
const nav = computed(() => [
  { to: '/gestion', label: 'Accueil', icon: 'M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3z', exact: true },
  { label: 'Commandes', icon: 'M4 4h16v4H4zM4 10h16v10H4z', soon: 'Phase 2' },
  { label: 'Catalogue', icon: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z', soon: 'Phase 1' },
  { label: 'Clients', icon: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm-8 9a8 8 0 0 1 16 0z', soon: 'Phase 2' },
  { label: 'Livraisons', icon: 'M3 7h11v9H3zM14 10h4l3 3v3h-7zM7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm10 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4z', soon: 'Phase 4' },
  ...(auth.can('team.manage') ? [{ to: '/gestion/equipe', label: 'Équipe', icon: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm-7 10a7 7 0 0 1 14 0zm15-10a3 3 0 1 0 0-6M22 21a6 6 0 0 0-4-5.6' }] : []),
  { to: '/gestion/parametres', label: 'Paramètres', icon: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z' },
])

function isActive(item) {
  return item.exact ? route.path === item.to : route.path.startsWith(item.to)
}

async function logout() {
  await auth.logout()
  router.replace('/gestion/connexion')
}

async function switchTo(store) {
  switchError.value = ''
  try {
    const { data } = await http.post(`/auth/switch/${store.id}`)
    window.location.assign(data.redirect)
  } catch (e) {
    switchError.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="min-h-screen lg:flex">
    <!-- Barre latérale (ordinateur) / menu déroulant (téléphone) -->
    <aside
      class="fixed inset-y-0 left-0 z-30 w-64 -translate-x-full border-r border-slate-200 bg-white transition lg:static lg:translate-x-0"
      :class="{ 'translate-x-0': menuOpen }"
    >
      <div class="flex h-16 items-center gap-2 border-b border-slate-100 px-4">
        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-emerald-700 font-semibold text-white">
          {{ (auth.store?.name ?? '?').charAt(0).toUpperCase() }}
        </span>
        <div class="min-w-0">
          <p class="truncate font-semibold">{{ auth.store?.name }}</p>
          <a :href="auth.store?.url" target="_blank" class="block truncate text-xs text-slate-500 hover:text-emerald-700">Voir le site ↗</a>
        </div>
      </div>

      <nav class="space-y-1 p-3">
        <template v-for="item in nav" :key="item.label">
          <RouterLink
            v-if="item.to"
            :to="item.to"
            class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium"
            :class="isActive(item) ? 'bg-emerald-50 text-emerald-800' : 'text-slate-700 hover:bg-slate-50'"
            @click="menuOpen = false"
          >
            <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><path :d="item.icon" /></svg>
            {{ item.label }}
          </RouterLink>
          <span v-else class="flex cursor-default items-center gap-3 rounded-lg px-3 py-2 text-sm text-slate-400" :title="`Arrive en ${item.soon}`">
            <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><path :d="item.icon" /></svg>
            {{ item.label }}
            <span class="ml-auto rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-slate-500">Bientôt</span>
          </span>
        </template>
      </nav>

      <div v-if="auth.profile?.other_stores?.length" class="border-t border-slate-100 p-3">
        <p class="px-3 pb-1 text-xs font-medium uppercase text-slate-400">Mes autres boutiques</p>
        <button
          v-for="store in auth.profile.other_stores"
          :key="store.id"
          class="block w-full truncate rounded-lg px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
          @click="switchTo(store)"
        >
          {{ store.name }}
        </button>
        <p v-if="switchError" class="px-3 text-sm text-red-600">{{ switchError }}</p>
      </div>
    </aside>
    <div v-if="menuOpen" class="fixed inset-0 z-20 bg-slate-900/30 lg:hidden" @click="menuOpen = false" />

    <div class="min-w-0 flex-1">
      <header class="sticky top-0 z-10 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur">
        <button class="btn-ghost -ml-2 px-2 lg:hidden" aria-label="Menu" @click="menuOpen = true">
          <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
        </button>
        <h1 class="truncate text-lg font-semibold">{{ route.meta.title }}</h1>
        <div class="ml-auto flex items-center gap-3">
          <span class="hidden text-right text-sm sm:block">
            <span class="block font-medium">{{ auth.user?.name }}</span>
            <span class="block text-xs text-slate-500">{{ auth.profile?.role?.label }}</span>
          </span>
          <button class="btn-secondary px-3 py-2" @click="logout">Déconnexion</button>
        </div>
      </header>

      <main class="mx-auto max-w-5xl p-4 sm:p-6">
        <RouterView />
      </main>
    </div>
  </div>
</template>
