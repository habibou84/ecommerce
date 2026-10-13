<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NotificationBell from '../components/NotificationBell.vue'
import { boot } from '../lib/boot'
import http, { errorMessage } from '../lib/http'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const menuOpen = ref(false)
const switchError = ref('')

// Modules à venir : affichés pour montrer la suite, sans lien
const deliveryHome = computed(() => {
  if (auth.can('shipments.manage')) return '/gestion/livraisons'
  if (auth.can('couriers.manage')) return '/gestion/livraisons/livreurs'
  if (auth.can('carriers.manage')) return '/gestion/livraisons/entreprises'
  return auth.can('store.manage') ? '/gestion/livraisons/zones' : null
})
const cashHome = computed(() => {
  if (auth.can('finance.view') || auth.can('cash.manage')) return '/gestion/caisse'
  if (auth.can('couriers.manage')) return '/gestion/caisse/livreurs'
  return auth.can('carriers.manage') ? '/gestion/caisse/a-recevoir' : null
})

const nav = computed(() => [
  { to: '/gestion', label: 'Accueil', icon: 'M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3z', exact: true },
  ...(auth.can('orders.view') ? [{ to: '/gestion/commandes', label: 'Commandes', icon: 'M4 4h16v4H4zM4 10h16v10H4z' }] : []),
  ...(auth.can('orders.confirm') ? [{ to: '/gestion/confirmation', label: 'À confirmer', icon: 'M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z' }] : []),
  ...(auth.can('products.view')
    ? [
        { to: '/gestion/catalogue', label: 'Catalogue', icon: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z' },
        { to: '/gestion/collections', label: 'Collections', icon: 'M4 6h16M4 12h16M4 18h10' },
      ]
    : []),
  ...(auth.can('stock.manage') ? [{ to: '/gestion/stock', label: 'Stock', icon: 'M3 7l9-4 9 4-9 4-9-4zm0 0v10l9 4 9-4V7M12 11v10' }] : []),
  ...(auth.can('customers.view') ? [{ to: '/gestion/clients', label: 'Clients', icon: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm-8 9a8 8 0 0 1 16 0z' }] : []),
  ...(auth.can('store.manage') ? [{ to: '/gestion/boutique-en-ligne', label: 'Boutique en ligne', icon: 'M3 9l1.5-5h15L21 9M3 9h18M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0M5 12v8h14v-8M10 20v-5h4v5' }] : []),
  // Livraisons : qui livre et comment (Mes livreurs, entreprises de livraison, zones, attribution)
  ...(deliveryHome.value ? [{ to: deliveryHome.value, label: 'Livraisons', icon: 'M3 7h11v9H3zM14 10h4l3 3v3h-7zM7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm10 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4z', match: '/gestion/livraisons' }] : []),
  // Caisse : tout l'argent (vue d'ensemble, argent des livreurs, à recevoir des entreprises)
  ...(cashHome.value ? [{ to: cashHome.value, label: 'Caisse', icon: 'M3 7h18v12H3zM3 11h18M7 15h3M16 4H8l-1 3h10z', match: '/gestion/caisse' }] : []),
  ...(auth.can('finance.view') || auth.can('orders.view') ? [{ to: '/gestion/statistiques', label: 'Statistiques', icon: 'M4 20V10M10 20V4M16 20v-7M22 20H2' }] : []),
  ...(auth.can('store.manage') ? [{ to: '/gestion/marketing', label: 'Marketing', icon: 'M3 11l18-8v18L3 13zM7 12v6a2 2 0 0 0 4 0v-4' }] : []),
  ...(auth.can('payments.manage') ? [{ to: '/gestion/paiements', label: 'Paiements', icon: 'M2 6h20v12H2zM2 10h20M6 15h4' }] : []),
  ...(auth.can('store.manage') ? [{ to: '/gestion/whatsapp', label: 'WhatsApp', icon: 'M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.1-5.4A8.4 8.4 0 1 1 21 11.5z' }] : []),
  ...(auth.can('billing.manage') ? [{ to: '/gestion/abonnement', label: 'Abonnement', icon: 'M12 2l3 6 6 .9-4.5 4.4 1 6.2L12 16.6 6.5 19.5l1-6.2L3 8.9 9 8z' }] : []),
  ...(auth.can('team.manage') ? [{ to: '/gestion/equipe', label: 'Équipe', icon: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm-7 10a7 7 0 0 1 14 0zm15-10a3 3 0 1 0 0-6M22 21a6 6 0 0 0-4-5.6' }] : []),
  { to: '/gestion/parametres', label: 'Paramètres', icon: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z' },
])

function isActive(item) {
  return item.exact ? route.path === item.to : route.path.startsWith(item.match ?? item.to)
}

// Session de support de la plateforme : bandeau pour le support (lui-même) et pour la boutique
const support = computed(() => auth.profile?.support ?? null)
const supportUntil = computed(() => support.value ? new Date(support.value.expires_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '')
async function endSupport() {
  await http.post(`/support-sessions/${support.value.id}/end`)
  await auth.fetch()
}
const helpUrl = computed(() => `https://wa.me/${String(boot.supportWhatsapp ?? '').replace(/\D/g, '')}?text=${encodeURIComponent(`Bonjour, j'ai besoin d'aide pour ma boutique ${auth.store?.name ?? ''} (${boot.store?.slug ?? ''}).`)}`)

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
          <a v-if="boot.supportWhatsapp" :href="helpUrl" target="_blank" rel="noopener" class="btn-ghost px-2 text-sm" title="Écrire au support Jibiat Marchand sur WhatsApp">💬 Aide</a>
          <NotificationBell />
          <span class="hidden text-right text-sm sm:block">
            <span class="block font-medium">{{ auth.user?.name }}</span>
            <span class="block text-xs text-slate-500">{{ auth.profile?.role?.label }}</span>
          </span>
          <button class="btn-secondary px-3 py-2" @click="logout">Déconnexion</button>
        </div>
      </header>

      <div v-if="support?.self" class="bg-amber-400 px-4 py-2 text-center text-sm font-medium text-slate-900">
        🛟 Session de support jusqu'à {{ supportUntil }} — vous agissez dans la boutique d'un marchand : « {{ support.reason }} »
      </div>
      <div v-else-if="support && auth.can('team.manage')" class="flex flex-wrap items-center justify-center gap-3 bg-sky-50 px-4 py-2 text-sm text-sky-900">
        <span>🛟 Le support Jibiat Marchand ({{ support.admin }}) a accès à votre gestion jusqu'à {{ supportUntil }} : « {{ support.reason }} »</span>
        <button class="underline" @click="endSupport">Mettre fin</button>
      </div>
      <main class="mx-auto max-w-5xl p-4 sm:p-6">
        <RouterView />
      </main>
    </div>
  </div>
</template>
