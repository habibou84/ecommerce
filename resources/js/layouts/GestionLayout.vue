<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NotificationBell from '../components/NotificationBell.vue'
import { inApp } from '../lib/app'
import { registerDevice, unregisterDevice } from '../lib/push'
import { boot } from '../lib/boot'
import http, { errorMessage } from '../lib/http'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const menuOpen = ref(false)
const switchError = ref('')

// Menu en groupes : un groupe n'affiche que les pages que le rôle peut ouvrir (droits lus sur les routes),
// disparaît s'il est vide et devient un lien direct s'il ne garde qu'une page
const groups = [
  { label: 'Ventes', icon: 'M5 8h14l-1 12H6zM9 8V6a3 3 0 0 1 6 0v2', items: [
    { to: '/gestion/commandes', label: 'Commandes' },
    { to: '/gestion/messages', label: 'Messages', badge: 'messages' },
    { to: '/gestion/confirmation', label: 'À confirmer' },
    { to: '/gestion/caisse/vente', label: 'Vente au comptoir' },
    { to: '/gestion/clients', label: 'Clients' },
    { to: '/gestion/paniers-abandonnes', label: 'Paniers abandonnés' },
    { to: '/gestion/statistiques', label: 'Statistiques' },
  ] },
  { label: 'Produits', icon: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z', items: [
    { to: '/gestion/catalogue', label: 'Mes produits' },
    { to: '/gestion/collections', label: 'Collections' },
    { to: '/gestion/stock', label: 'Stock' },
  ] },
  { label: 'Livraisons', icon: 'M3 7h11v9H3zM14 10h4l3 3v3h-7zM7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm10 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4z', items: [
    { to: '/gestion/livraisons', label: 'En cours', exact: true },
    { to: '/gestion/livraisons/livreurs', label: 'Mes livreurs' },
    { to: '/gestion/livraisons/entreprises', label: 'Entreprises de livraison' },
    { to: '/gestion/livraisons/zones', label: 'Zones et tarifs' },
    { to: '/gestion/livraisons/qui-livre-quoi', label: 'Qui livre quoi' },
  ] },
  { label: 'Caisse', icon: 'M3 7h18v12H3zM3 11h18M7 15h3M16 4H8l-1 3h10z', items: [
    { to: '/gestion/caisse', label: 'Vue d\'ensemble', exact: true },
    { to: '/gestion/caisse/livreurs', label: 'Argent des livreurs' },
    { to: '/gestion/caisse/a-recevoir', label: 'À recevoir des entreprises' },
  ] },
  { label: 'Boutique en ligne', icon: 'M3 9l1.5-5h15L21 9M3 9h18M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0M5 12v8h14v-8M10 20v-5h4v5', items: [
    { to: '/gestion/boutique-en-ligne', label: 'Apparence', exact: true },
    { to: '/gestion/boutique-en-ligne/pages', label: 'Pages et menus', also: ['/gestion/boutique-en-ligne/menus'] },
    { to: '/gestion/boutique-en-ligne/domaine', label: 'Nom de domaine' },
    { to: '/gestion/marketing', label: 'Promotions' },
  ] },
  { label: 'Réglages', icon: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z', items: [
    { to: '/gestion/parametres', label: 'Ma boutique' },
    { to: '/gestion/compte', label: 'Mon compte' },
    { to: '/gestion/equipe', label: 'Équipe' },
    { to: '/gestion/paiements', label: 'Paiement en ligne' },
    { to: '/gestion/whatsapp', label: 'WhatsApp' },
    { to: '/gestion/abonnement', label: 'Abonnement' },
    { to: '/gestion/mes-boutiques', label: 'Mes boutiques' },
  ] },
]

function canOpen(path) {
  const { meta } = router.resolve(path)
  if (meta.owner && !auth.profile?.is_owner) return false
  if (meta.permissionAny) return meta.permissionAny.some((p) => auth.can(p))
  return !meta.permission || auth.can(meta.permission)
}

const nav = computed(() => [
  { to: '/gestion', label: 'Accueil', icon: 'M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3z', exact: true },
  ...groups.flatMap((group) => {
    const items = group.items.filter((item) => canOpen(item.to))
    if (!items.length) return []
    return items.length === 1 ? [{ ...items[0], label: group.label, icon: group.icon }] : [{ ...group, items }]
  }),
])

function isActive(item) {
  if (item.items) return item.items.some(isActive)
  if (item.exact) return route.path === item.to
  return [item.to, ...(item.also ?? [])].some((p) => route.path === p || route.path.startsWith(`${p}/`))
}

// Un seul groupe ouvert à la fois : celui de la page affichée, ou celui que l'on vient d'ouvrir
const openGroup = ref(null)
watch(() => route.path, () => {
  openGroup.value = nav.value.find((item) => item.items && isActive(item))?.label ?? openGroup.value
}, { immediate: true })
function toggle(group) {
  openGroup.value = openGroup.value === group.label ? null : group.label
}

// Pastille des messages WhatsApp non lus
const badges = ref({ messages: 0 })
const groupBadge = (group) => group.items.reduce((sum, item) => sum + (badges.value[item.badge] ?? 0), 0)
let badgeTimer
async function refreshBadges() {
  if (document.hidden || !canOpen('/gestion/messages')) return
  try {
    badges.value.messages = (await http.get('/conversations/unread')).data.data.count
  } catch {
    // pastille seulement : une erreur passagère ne gêne pas la page
  }
}
onMounted(() => {
  registerDevice()
  refreshBadges()
  badgeTimer = setInterval(refreshBadges, 30000)
})
onBeforeUnmount(() => clearInterval(badgeTimer))
watch(() => route.path, refreshBadges)

// Session de support de la plateforme : bandeau pour le support (lui-même) et pour la boutique
const support = computed(() => auth.profile?.support ?? null)
const supportUntil = computed(() => support.value ? new Date(support.value.expires_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '')
async function endSupport() {
  await http.post(`/support-sessions/${support.value.id}/end`)
  await auth.fetch()
}
const helpUrl = computed(() => `https://wa.me/${String(boot.supportWhatsapp ?? '').replace(/\D/g, '')}?text=${encodeURIComponent(`Bonjour, j'ai besoin d'aide pour ma boutique ${auth.store?.name ?? ''} (${boot.store?.slug ?? ''}).`)}`)

async function logout() {
  await unregisterDevice()
  await auth.logout()
  router.replace('/gestion/connexion')
}

const addressStores = computed(() => auth.profile?.stores ?? [])
function pickStore(value) {
  // Vue d'ensemble : chiffres et livreurs de toutes les boutiques
  if (value === 'all') {
    router.push('/gestion/mes-boutiques/statistiques')
    return
  }
  if (Number(value) !== auth.store?.id) auth.switchTo(Number(value))
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
      class="fixed inset-y-0 left-0 z-30 w-64 -translate-x-full overflow-y-auto border-r border-slate-200 bg-white transition lg:static lg:translate-x-0"
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
          <div v-if="item.items">
            <button
              type="button"
              class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium"
              :class="isActive(item) ? 'text-emerald-800' : 'text-slate-700 hover:bg-slate-50'"
              :aria-expanded="openGroup === item.label"
              @click="toggle(item)"
            >
              <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><path :d="item.icon" /></svg>
              {{ item.label }}
              <span v-if="groupBadge(item) && openGroup !== item.label" class="ml-auto rounded-full bg-emerald-600 px-1.5 text-[11px] font-semibold text-white">{{ groupBadge(item) }}</span>
              <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 text-slate-400 transition" :class="[{ 'rotate-90': openGroup === item.label }, groupBadge(item) && openGroup !== item.label ? '' : 'ml-auto']" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 6 6 6-6 6" /></svg>
            </button>
            <div v-show="openGroup === item.label" class="mb-1 ml-5 space-y-0.5 border-l border-slate-200 pl-3">
              <RouterLink
                v-for="sub in item.items"
                :key="sub.to"
                :to="sub.to"
                class="flex items-center rounded-lg px-3 py-1.5 text-sm"
                :class="isActive(sub) ? 'bg-emerald-50 font-medium text-emerald-800' : 'text-slate-600 hover:bg-slate-50'"
                @click="menuOpen = false"
              >
                {{ sub.label }}
                <span v-if="badges[sub.badge]" class="ml-auto rounded-full bg-emerald-600 px-1.5 text-[11px] font-semibold text-white">{{ badges[sub.badge] }}</span>
              </RouterLink>
            </div>
          </div>
          <RouterLink
            v-else
            :to="item.to"
            class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium"
            :class="isActive(item) ? 'bg-emerald-50 text-emerald-800' : 'text-slate-700 hover:bg-slate-50'"
            @click="menuOpen = false"
          >
            <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><path :d="item.icon" /></svg>
            {{ item.label }}
          </RouterLink>
        </template>
      </nav>

      <div v-if="inApp" class="border-t border-slate-100 p-3">
        <a :href="`${boot.platformUrl.replace(/\/$/, '')}/connexion?app=1&choisir=1`" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">⇄ Changer de boutique</a>
      </div>
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
        <!-- Boutiques de cette adresse : on passe de l'une à l'autre sans changer d'adresse -->
        <select
          v-if="addressStores.length > 1"
          :value="auth.store?.id"
          class="min-w-0 max-w-[10rem] truncate rounded-lg border border-slate-300 bg-white py-1.5 pl-2 pr-7 text-sm font-semibold sm:max-w-[14rem]"
          aria-label="Boutique"
          @change="pickStore($event.target.value)"
        >
          <option v-for="s in addressStores" :key="s.id" :value="s.id">{{ s.name }}</option>
          <option v-if="auth.profile?.is_owner" value="all">Toutes les boutiques</option>
        </select>
        <h1 class="truncate text-lg font-semibold" :class="{ 'hidden sm:block': addressStores.length > 1 }">{{ route.meta.title }}</h1>
        <div class="ml-auto flex shrink-0 items-center gap-1 sm:gap-3">
          <a v-if="boot.supportWhatsapp" :href="helpUrl" target="_blank" rel="noopener" class="btn-ghost px-2 text-sm" title="Écrire au support Jibiat Marchand sur WhatsApp" aria-label="Aide">💬<span class="hidden sm:inline"> Aide</span></a>
          <NotificationBell />
          <RouterLink to="/gestion/compte" class="hidden rounded-lg px-2 py-1 text-right text-sm hover:bg-slate-50 sm:block" title="Mon compte">
            <span class="block font-medium">{{ auth.user?.name }}</span>
            <span class="block text-xs text-slate-500">{{ auth.profile?.role?.label }}</span>
          </RouterLink>
          <button class="btn-secondary px-2 py-2 sm:px-3" aria-label="Déconnexion" title="Déconnexion" @click="logout">
            <span class="hidden sm:inline">Déconnexion</span>
            <svg viewBox="0 0 24 24" class="h-5 w-5 sm:hidden" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5M15 12H3" /></svg>
          </button>
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
