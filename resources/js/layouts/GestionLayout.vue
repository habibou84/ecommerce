<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
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
    { to: '/gestion/parametres', label: 'Informations de la boutique' },
    { to: '/gestion/equipe', label: 'Équipe' },
    { to: '/gestion/paiements', label: 'Paiement en ligne' },
    { to: '/gestion/whatsapp', label: 'WhatsApp' },
    { to: '/gestion/textes-messages', label: 'Textes des messages' },
    { to: '/gestion/mes-boutiques', label: 'Ajouter une boutique', single: true },
  ] },
]

function canOpen(path) {
  const { meta } = router.resolve(path)
  if (meta.owner && !auth.profile?.is_owner) return false
  if (meta.permissionAny) return meta.permissionAny.some((p) => auth.can(p))
  return !meta.permission || auth.can(meta.permission)
}

// Propriétaire de plusieurs boutiques : vue d'ensemble en haut du menu (sinon « Ajouter une boutique » dans Réglages)
const several = computed(() => !!auth.profile?.is_owner && ((auth.profile?.stores?.length ?? 0) > 1 || !!auth.profile?.owns_several))
const nav = computed(() => [
  { to: '/gestion', label: 'Accueil', icon: 'M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3z', exact: true },
  ...(several.value ? [{ to: '/gestion/mes-boutiques', label: 'Vue d\'ensemble', icon: 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z' }] : []),
  ...groups.flatMap((group) => {
    const items = group.items.filter((item) => canOpen(item.to) && !(item.single && several.value))
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

// Barre du bas (téléphone) : accueil, commandes, nouvelle commande, messages, le reste du menu
const bottomTabs = computed(() => [
  { to: '/gestion', label: 'Accueil', icon: 'home', exact: true },
  { to: '/gestion/commandes', label: 'Commandes', icon: 'orders' },
])
const canOrder = computed(() => canOpen('/gestion/commandes/nouvelle'))

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

// Boutiques de cette adresse : on passe de l'une à l'autre sans changer d'adresse
const addressStores = computed(() => auth.profile?.stores ?? [])
const storeMenu = ref(false)
const profileMenu = ref(false)
// Confirmation du changement de boutique (quelques secondes)
const switchNotice = ref('')
let switchTimer
watch(() => auth.switchedTo, (value) => {
  if (!value) return
  switchNotice.value = `Vous êtes maintenant dans ${value.name}`
  clearTimeout(switchTimer)
  switchTimer = setTimeout(() => (switchNotice.value = ''), 3500)
  menuOpen.value = false
  refreshBadges()
})
function pickStore(id) {
  storeMenu.value = false
  if (id !== auth.store?.id) auth.switchTo(id)
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
      class="fixed inset-y-0 left-0 z-30 flex w-64 -translate-x-full flex-col overflow-y-auto border-r border-slate-200 bg-white transition lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
      :class="{ 'translate-x-0': menuOpen }"
    >
      <!-- Boutique ouverte ; avec plusieurs boutiques à cette adresse, c'est ici qu'on change de boutique -->
      <div class="relative border-b border-slate-100">
        <button
          type="button"
          class="flex h-16 w-full items-center gap-2 px-4 text-left"
          :class="{ 'hover:bg-slate-50': addressStores.length > 1 }"
          :disabled="addressStores.length < 2"
          :aria-expanded="storeMenu"
          @click="storeMenu = !storeMenu"
        >
          <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-emerald-700 font-semibold text-white">
            {{ (auth.store?.name ?? '?').charAt(0).toUpperCase() }}
          </span>
          <span class="min-w-0 flex-1">
            <span class="block truncate font-semibold">{{ auth.store?.name }}</span>
            <span v-if="addressStores.length > 1" class="block truncate text-xs text-slate-500">Changer de boutique</span>
            <span v-else class="block h-4" />
          </span>
          <svg v-if="addressStores.length > 1" viewBox="0 0 24 24" class="h-4 w-4 shrink-0 text-slate-400" fill="none" stroke="currentColor" stroke-width="2"><path d="m7 10 5 5 5-5" /></svg>
        </button>
        <!-- Sous le nom, à sa place (plus de chevauchement) -->
        <a v-if="addressStores.length < 2" :href="auth.store?.url" target="_blank" class="absolute bottom-2.5 left-14 inline-flex items-center gap-1 text-xs text-slate-600 hover:text-emerald-700">Voir le site <Icon name="external" :size="12" /></a>
        <div v-if="storeMenu" class="absolute inset-x-2 top-14 z-40 rounded-xl border border-slate-200 bg-white p-1 shadow-lg">
          <button
            v-for="s in addressStores"
            :key="s.id"
            type="button"
            class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-50"
            :class="s.id === auth.store?.id ? 'font-semibold text-emerald-800' : 'text-slate-700'"
            @click="pickStore(s.id)"
          >
            <span class="flex-1 truncate">{{ s.name }}</span>
            <span v-if="s.id === auth.store?.id">✓</span>
          </button>
          <a :href="auth.store?.url" target="_blank" class="mt-1 flex items-center gap-1.5 border-t border-slate-100 px-3 pb-2 pt-3 text-sm text-slate-700 hover:bg-slate-50">Voir le site de {{ auth.store?.name }} <Icon name="external" :size="14" /></a>
          <div v-if="auth.profile?.is_owner" class="border-t border-slate-100 pt-1">
            <RouterLink to="/gestion/mes-boutiques/statistiques" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50" @click="storeMenu = false; menuOpen = false">Vue d'ensemble</RouterLink>
            <RouterLink to="/gestion/mes-boutiques" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50" @click="storeMenu = false; menuOpen = false">Gérer mes boutiques</RouterLink>
          </div>
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
        <p class="px-3 pb-1 text-xs font-medium uppercase text-slate-400">Autres comptes</p>
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

      <!-- Personne connectée, tout en bas : son compte, l'abonnement, la déconnexion -->
      <div class="relative mt-auto border-t border-slate-100 p-3">
        <div v-if="profileMenu" class="absolute inset-x-3 bottom-full mb-1 rounded-xl border border-slate-200 bg-white p-1 shadow-lg">
          <RouterLink to="/gestion/compte" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50" @click="profileMenu = false; menuOpen = false">Mon compte</RouterLink>
          <RouterLink v-if="auth.can('billing.manage')" to="/gestion/abonnement" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50" @click="profileMenu = false; menuOpen = false">
            Abonnement<span v-if="auth.store?.subscription" class="block text-xs text-slate-500">Offre {{ auth.store.subscription.plan }}<template v-if="auth.store.subscription.status === 'trialing'"> · essai</template></span>
          </RouterLink>
          <button type="button" class="block w-full rounded-lg border-t border-slate-100 px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50" @click="logout">Déconnexion</button>
        </div>
        <button type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left hover:bg-slate-50" :aria-expanded="profileMenu" @click="profileMenu = !profileMenu">
          <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-slate-200 text-sm font-semibold text-slate-700">{{ (auth.user?.name ?? '?').charAt(0).toUpperCase() }}</span>
          <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-medium">{{ auth.user?.name }}</span>
            <span class="block truncate text-xs text-slate-500">{{ auth.profile?.role?.label }}</span>
          </span>
          <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 text-slate-400 transition" :class="{ 'rotate-180': profileMenu }" fill="none" stroke="currentColor" stroke-width="2"><path d="m7 14 5-5 5 5" /></svg>
        </button>
      </div>
    </aside>
    <div v-if="menuOpen" class="fixed inset-0 z-20 bg-slate-900/30 lg:hidden" @click="menuOpen = false" />

    <div class="min-w-0 flex-1">
      <header class="sticky top-0 z-10 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur">
        <button class="btn-ghost -ml-2 px-2 lg:hidden" aria-label="Menu" @click="menuOpen = true">
          <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
        </button>
        <h1 class="min-w-0 truncate text-base font-semibold sm:text-lg">{{ route.meta.title }}</h1>
        <div class="ml-auto flex shrink-0 items-center gap-1 sm:gap-3">
          <a v-if="boot.supportWhatsapp" :href="helpUrl" target="_blank" rel="noopener" class="btn-ghost gap-1.5 px-2 text-sm" title="Écrire au support Jibiat Marchand sur WhatsApp" aria-label="Aide"><Icon name="help" /><span class="hidden sm:inline">Aide</span></a>
          <NotificationBell />
        </div>
      </header>

      <div v-if="support?.self" class="bg-amber-400 px-4 py-2 text-center text-sm font-medium text-slate-900">
        <Icon name="help" :size="16" class="mr-1 inline-block align-[-3px]" />Session de support jusqu'à {{ supportUntil }} — vous agissez dans la boutique d'un marchand : « {{ support.reason }} »
      </div>
      <div v-else-if="support && auth.can('team.manage')" class="flex flex-wrap items-center justify-center gap-3 bg-sky-50 px-4 py-2 text-sm text-sky-900">
        <span><Icon name="help" :size="16" class="mr-1 inline-block align-[-3px]" />Le support Jibiat Marchand ({{ support.admin }}) a accès à votre gestion jusqu'à {{ supportUntil }} : « {{ support.reason }} »</span>
        <button class="underline" @click="endSupport">Mettre fin</button>
      </div>
      <main class="mx-auto max-w-6xl p-4 pb-24 sm:p-6 sm:pb-24 lg:pb-6">
        <!-- Changement de boutique : la page est recréée avec les données de la nouvelle boutique -->
        <RouterView v-slot="{ Component }">
          <component :is="Component" :key="auth.store?.id" />
        </RouterView>
      </main>
    </div>
    <!-- Téléphone : les gestes de tous les jours sous le pouce -->
    <nav class="hide-on-keyboard fixed inset-x-0 bottom-0 z-10 border-t border-slate-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden" aria-label="Navigation principale">
      <div class="mx-auto grid max-w-md grid-cols-5 items-end">
        <RouterLink v-for="tab in bottomTabs.slice(0, 2)" :key="tab.to" :to="tab.to" class="flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium" :class="isActive(tab) ? 'text-emerald-700' : 'text-slate-600'">
          <Icon :name="tab.icon" :size="22" />{{ tab.label }}
        </RouterLink>
        <div class="flex justify-center">
          <RouterLink v-if="canOrder" to="/gestion/commandes/nouvelle" class="-mt-5 mb-1 grid h-14 w-14 place-items-center rounded-full bg-emerald-700 text-white shadow-lg ring-4 ring-white active:scale-95" aria-label="Nouvelle commande">
            <Icon name="plus" :size="26" />
          </RouterLink>
        </div>
        <RouterLink v-if="canOpen('/gestion/messages')" to="/gestion/messages" class="relative flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium" :class="isActive({ to: '/gestion/messages' }) ? 'text-emerald-700' : 'text-slate-600'">
          <Icon name="messages" :size="22" />Messages
          <span v-if="badges.messages" class="absolute right-[calc(50%-20px)] top-1 min-w-[18px] rounded-full bg-red-600 px-1 text-center text-[10px] font-semibold leading-[18px] text-white">{{ badges.messages > 99 ? '99+' : badges.messages }}</span>
        </RouterLink>
        <span v-else />
        <button type="button" class="flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium text-slate-600" @click="menuOpen = true">
          <Icon name="menu" :size="22" />Plus
        </button>
      </div>
    </nav>
    <div v-if="switchNotice" class="fixed inset-x-4 bottom-20 lg:bottom-4 z-50 mx-auto max-w-sm rounded-xl bg-slate-900 px-4 py-3 text-center text-sm font-medium text-white shadow-lg" role="status">
      {{ switchNotice }}
    </div>
  </div>
</template>
