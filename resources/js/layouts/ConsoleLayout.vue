<script setup>
import { onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useConsoleStore } from '../stores/console'

// Logo servi tel quel depuis public/ (pas importé par Vite)
const LOGO = '/brand/logo-256.png'

const console_ = useConsoleStore()
const router = useRouter()
const nav = [
  { to: '/console', label: 'Tableau de bord', exact: true },
  { to: '/console/boutiques', label: 'Espaces marchands' },
  { to: '/console/paiements', label: 'Paiements', badge: 'pendingPayments' },
  { to: '/console/whatsapp', label: 'WhatsApp', badge: 'unreadMessages' },
  { to: '/console/factures', label: 'Factures' },
  { to: '/console/parrainage', label: 'Parrainage' },
  { to: '/console/offres', label: 'Offres' },
  { to: '/console/ia', label: 'Assistant IA' },
]

// Paiements à valider : relus chaque minute pour la pastille du menu
let timer
onMounted(() => {
  console_.refreshPending()
  timer = setInterval(() => console_.refreshPending(), 60000)
})
onUnmounted(() => clearInterval(timer))

async function logout() {
  await console_.logout()
  router.push('/console/connexion')
}
</script>

<template>
  <div class="min-h-screen bg-slate-50">
    <header class="border-b border-slate-800 bg-slate-900 text-white">
      <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
        <span class="flex items-center gap-2 font-semibold"><img :src="LOGO" alt="" class="h-7 w-7" width="28" height="28">Jibiat Marchand <span class="rounded bg-amber-400 px-1.5 py-0.5 text-xs text-slate-900">Console</span></span>
        <nav class="flex flex-wrap gap-1 text-sm">
          <RouterLink v-for="item in nav" :key="item.to" :to="item.to" class="rounded px-3 py-1.5"
                      :class="(item.exact ? $route.path === item.to : $route.path.startsWith(item.to)) ? 'bg-white/15' : 'text-slate-300 hover:text-white'">{{ item.label }}<span v-if="item.badge && console_[item.badge]" class="ml-1.5 rounded-full bg-amber-400 px-1.5 text-xs font-semibold text-slate-900">{{ console_[item.badge] }}</span></RouterLink>
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
