<script setup>
import { useRouter } from 'vue-router'
import { useConsoleStore } from '../stores/console'

const console_ = useConsoleStore()
const router = useRouter()
const nav = [
  { to: '/console', label: 'Tableau de bord', exact: true },
  { to: '/console/boutiques', label: 'Boutiques' },
  { to: '/console/factures', label: 'Factures' },
  { to: '/console/offres', label: 'Offres' },
]

async function logout() {
  await console_.logout()
  router.push('/console/connexion')
}
</script>

<template>
  <div class="min-h-screen bg-slate-50">
    <header class="border-b border-slate-800 bg-slate-900 text-white">
      <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
        <span class="font-semibold">Jibiat Marchand <span class="rounded bg-amber-400 px-1.5 py-0.5 text-xs text-slate-900">Console</span></span>
        <nav class="flex flex-wrap gap-1 text-sm">
          <RouterLink v-for="item in nav" :key="item.to" :to="item.to" class="rounded px-3 py-1.5"
                      :class="(item.exact ? $route.path === item.to : $route.path.startsWith(item.to)) ? 'bg-white/15' : 'text-slate-300 hover:text-white'">{{ item.label }}</RouterLink>
        </nav>
        <span class="ml-auto text-sm text-slate-300">{{ console_.admin?.name }}</span>
        <button class="text-sm text-slate-300 hover:text-white" @click="logout">Déconnexion</button>
      </div>
    </header>
    <main class="mx-auto max-w-6xl px-4 py-6">
      <RouterView />
    </main>
  </div>
</template>
