<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { install, isIos, promptInstall } from '../lib/install'
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
  { to: '/console/whatsapp', label: 'WhatsApp', badge: 'unreadMessages', exact: true },
  { to: '/console/whatsapp-boutiques', label: 'WhatsApp des boutiques' },
  { to: '/console/factures', label: 'Factures' },
  { to: '/console/parrainage', label: 'Parrainage' },
  { to: '/console/offres', label: 'Offres' },
  { to: '/console/fonctions', label: 'Fonctions' },
  { to: '/console/materiel', label: 'Matériel' },
  { to: '/console/cles', label: 'Clés d\'accès' },
  { to: '/console/ia', label: 'Assistant IA' },
  { to: '/console/utilisateurs', label: 'Utilisateurs' },
  { to: '/console/equipe', label: 'Équipe' },
  { to: '/console/sante', label: 'Santé' },
  { to: '/console/journal', label: 'Journal' },
]

// Paiements à valider : relus chaque minute pour la pastille du menu
let timer
// Console installable : le service worker (notifications, démarrage hors ligne) et le bouton « Installer »
const iosHelp = ref(false)
const showInstall = () => !install.installed && (install.prompt || isIos())
async function installApp() {
  if (install.prompt) await promptInstall()
  else iosHelp.value = !iosHelp.value
}
onMounted(() => {
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {})
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
        <RouterLink to="/console/compte" class="ml-auto text-sm text-slate-300 hover:text-white">{{ console_.admin?.name }}<span v-if="console_.admin && !console_.admin.email" class="ml-1.5 rounded-full bg-amber-400 px-1.5 text-xs font-semibold text-slate-900" title="Ajoutez votre e-mail pour recevoir le code de connexion">!</span></RouterLink>
        <button v-if="showInstall()" class="rounded bg-white/10 px-2.5 py-1 text-sm text-white hover:bg-white/20" @click="installApp">Installer</button>
        <button class="text-sm text-slate-300 hover:text-white" @click="logout">Déconnexion</button>
      </div>
      <p v-if="iosHelp" class="mx-auto max-w-6xl px-4 pb-3 text-sm text-slate-300">Sur iPhone : touchez le bouton Partager de Safari, puis « Sur l'écran d'accueil ».</p>
    </header>
    <main class="mx-auto max-w-6xl px-4 py-6">
      <RouterView />
    </main>
  </div>
</template>
