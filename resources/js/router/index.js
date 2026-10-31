import { createRouter, createWebHistory } from 'vue-router'
import { boot } from '../lib/boot'
import { useAuthStore } from '../stores/auth'
import { useConsoleStore } from '../stores/console'

// jibiat-marchand.com : création de boutique, retrouver ses boutiques
const platformRoutes = [
  { path: '/creer-ma-boutique', component: () => import('../views/platform/Signup.vue') },
  { path: '/connexion', component: () => import('../views/platform/Login.vue') },
  { path: '/cle/:code?', component: () => import('../views/platform/AccessKey.vue'), props: true },
  // Fenêtre Facebook de la connexion WhatsApp, pour toutes les boutiques
  { path: '/whatsapp/facebook', component: () => import('../views/platform/WhatsAppFacebook.vue') },
  // Écran du live (télé, tablette, ordinateur) : code à 4 chiffres, puis affichage du live de la boutique
  { path: '/ecran/:token?', component: () => import('../views/platform/LiveScreen.vue'), props: true },
  { path: '/mot-de-passe-oublie', component: () => import('../views/auth/ForgotPassword.vue'), props: { loginPath: '/connexion' } },
]

// {slug}.jibiat-marchand.com : gestion de la boutique
const storeRoutes = [
  { path: '/gestion/connexion', component: () => import('../views/auth/Login.vue'), meta: { guest: true } },
  {
    path: '/gestion/mot-de-passe-oublie',
    component: () => import('../views/auth/ForgotPassword.vue'),
    props: { loginPath: '/gestion/connexion' },
    meta: { guest: true },
  },
  { path: '/invitation/:token', component: () => import('../views/auth/Invitation.vue'), props: true, meta: { guest: true } },
  // Application des livreurs de la boutique
  {
    path: '/livreur/connexion',
    component: () => import('../views/auth/Login.vue'),
    props: { home: '/livreur', title: 'Application livreur' },
    meta: { guest: true },
  },
  {
    path: '/livreur',
    component: () => import('../layouts/CourierLayout.vue'),
    meta: { auth: true, courier: true },
    children: [
      { path: '', component: () => import('../views/courier/Missions.vue'), meta: { title: 'Mes livraisons' } },
      { path: 'missions/:id(\\d+)', component: () => import('../views/courier/Mission.vue'), props: true, meta: { title: 'Livraison' } },
      { path: 'caisse', component: () => import('../views/courier/Money.vue'), meta: { title: 'Ma caisse' } },
      { path: 'argent', redirect: '/livreur/caisse' },
      { path: 'compte', component: () => import('../views/courier/Account.vue'), meta: { title: 'Mon compte' } },
    ],
  },
  { path: '/livreur/:any(.*)*', redirect: '/livreur' },
  // Mode présentation du live : plein écran, sans le menu de la gestion (partagé à l'écran dans TikTok)
  { path: '/gestion/live/:id(\\d+)/presentation', component: () => import('../views/gestion/marketing/LivePresentation.vue'), props: true, meta: { platform: 'live_screens', auth: true, title: 'Mode présentation', permission: 'orders.manage' } },
  {
    path: '/gestion',
    component: () => import('../layouts/GestionLayout.vue'),
    meta: { auth: true },
    children: [
      { path: '', component: () => import('../views/gestion/Dashboard.vue'), meta: { title: 'Accueil' } },
      { path: 'commandes', component: () => import('../views/gestion/orders/OrderList.vue'), meta: { title: 'Commandes', permission: 'orders.view' } },
      { path: 'commandes/retours', component: () => import('../views/gestion/orders/Returns.vue'), meta: { title: 'Retours et échanges', permission: 'orders.manage' } },
      { path: 'commandes/nouvelle', component: () => import('../views/gestion/orders/OrderForm.vue'), meta: { title: 'Nouvelle commande', permission: 'orders.manage' } },
      { path: 'commandes/:id(\\d+)', component: () => import('../views/gestion/orders/OrderDetail.vue'), props: true, meta: { title: 'Commande', permission: 'orders.view' } },
      { path: 'commandes/:id(\\d+)/modifier', component: () => import('../views/gestion/orders/OrderForm.vue'), props: true, meta: { title: 'Modifier la commande', permission: 'orders.view' } },
      { path: 'paniers-abandonnes', component: () => import('../views/gestion/orders/AbandonedCarts.vue'), meta: { title: 'Paniers abandonnés', permission: 'orders.manage' } },
      { path: 'messages/:id(\\d+)?', component: () => import('../views/gestion/messages/Inbox.vue'), props: true, meta: { title: 'Messages', permissionAny: ['orders.manage', 'orders.confirm'] } },
      { path: 'confirmation', component: () => import('../views/gestion/orders/ConfirmationQueue.vue'), meta: { title: 'À confirmer', permission: 'orders.confirm' } },
      { path: 'clients', component: () => import('../views/gestion/customers/CustomerList.vue'), meta: { title: 'Clients', permission: 'customers.view' } },
      { path: 'clients/:id(\\d+)', component: () => import('../views/gestion/customers/CustomerDetail.vue'), props: true, meta: { title: 'Client', permission: 'customers.view' } },
      { path: 'zones-de-livraison', redirect: '/gestion/livraisons/zones' },
      { path: 'catalogue', component: () => import('../views/gestion/catalog/ProductList.vue'), meta: { title: 'Mes produits', permission: 'products.view' } },
      { path: 'catalogue/nouveau', component: () => import('../views/gestion/catalog/ProductEdit.vue'), meta: { title: 'Nouveau produit', permission: 'products.manage' } },
      { path: 'catalogue/import', component: () => import('../views/gestion/catalog/CatalogImport.vue'), meta: { title: 'Importer des produits', permission: 'products.manage' } },
      { path: 'catalogue/:id(\\d+)', component: () => import('../views/gestion/catalog/ProductEdit.vue'), props: true, meta: { title: 'Produit', permission: 'products.view' } },
      { path: 'collections', component: () => import('../views/gestion/catalog/Collections.vue'), meta: { title: 'Collections', permission: 'products.view' } },
      { path: 'stock', component: () => import('../views/gestion/stock/StockLevels.vue'), meta: { title: 'Stock', permission: 'stock.manage' } },
      { path: 'stock/commandes', component: () => import('../views/gestion/stock/PurchaseOrders.vue'), meta: { platform: 'suppliers', title: 'Commandes fournisseurs', permission: 'stock.manage' } },
      { path: 'stock/receptions', component: () => import('../views/gestion/stock/StockReceipts.vue'), meta: { title: 'Stock', permission: 'stock.manage' } },
      { path: 'stock/transferts', component: () => import('../views/gestion/stock/StockTransfers.vue'), meta: { title: 'Stock', permission: 'stock.manage' } },
      { path: 'stock/inventaires', component: () => import('../views/gestion/stock/StockCounts.vue'), meta: { title: 'Stock', permission: 'stock.manage' } },
      { path: 'stock/inventaires/:id(\\d+)', component: () => import('../views/gestion/stock/CountSheet.vue'), props: true, meta: { title: 'Inventaire', permission: 'stock.manage' } },
      { path: 'stock/journal', component: () => import('../views/gestion/stock/StockMovements.vue'), meta: { title: 'Stock', permission: 'stock.manage' } },
      { path: 'stock/emplacements', component: () => import('../views/gestion/stock/StockLocations.vue'), meta: { title: 'Stock', permission: 'stock.manage' } },
      // Livraisons : qui livre et comment (les anciennes adresses « expeditions » et « livreurs » redirigent ici)
      { path: 'livraisons', component: () => import('../views/gestion/shipping/Shipments.vue'), meta: { title: 'Livraisons en cours', permission: 'shipments.manage' } },
      { path: 'livraisons/livreurs', component: () => import('../views/gestion/couriers/Couriers.vue'), meta: { title: 'Mes livreurs', permission: 'couriers.manage' } },
      { path: 'livraisons/entreprises', component: () => import('../views/gestion/shipping/Carriers.vue'), meta: { title: 'Entreprises de livraison', permission: 'carriers.manage' } },
      { path: 'livraisons/tarifs', component: () => import('../views/gestion/DeliveryRates.vue'), meta: { title: 'Tarifs de livraison', permission: 'store.manage' } },
      { path: 'livraisons/zones', component: () => import('../views/gestion/DeliveryZones.vue'), meta: { title: 'Zones avancées', permission: 'store.manage' } },
      { path: 'livraisons/qui-livre-quoi', component: () => import('../views/gestion/shipping/ShippingRules.vue'), meta: { title: 'Qui livre quoi', permission: 'shipments.manage' } },
      { path: 'expeditions', redirect: '/gestion/livraisons' },
      { path: 'expeditions/transporteurs', redirect: '/gestion/livraisons/entreprises' },
      { path: 'expeditions/regles', redirect: '/gestion/livraisons/qui-livre-quoi' },
      { path: 'expeditions/reversements', redirect: '/gestion/caisse/a-recevoir' },
      { path: 'livreurs', redirect: '/gestion/livraisons/livreurs' },
      { path: 'livreurs/caisse', redirect: '/gestion/caisse/livreurs' },
      { path: 'boutique-en-ligne', component: () => import('../views/gestion/online-store/OnlineStore.vue'), meta: { title: 'Boutique en ligne', permission: 'store.manage' } },
      { path: 'boutique-en-ligne/pages', component: () => import('../views/gestion/online-store/Pages.vue'), meta: { title: 'Boutique en ligne', permission: 'store.manage' } },
      { path: 'boutique-en-ligne/domaine', component: () => import('../views/gestion/online-store/Domains.vue'), meta: { platform: 'custom_domain', title: 'Boutique en ligne', permission: 'store.manage' } },
      { path: 'boutique-en-ligne/menus', component: () => import('../views/gestion/online-store/Menus.vue'), meta: { title: 'Boutique en ligne', permission: 'store.manage' } },
      { path: 'marketing', component: () => import('../views/gestion/marketing/Promotions.vue'), meta: { title: 'Promotions', permission: 'store.manage' } },
      { path: 'marketing/reseaux', component: () => import('../views/gestion/marketing/Social.vue'), meta: { platform: 'social_meta|social_tiktok', title: 'Réseaux sociaux', permission: 'store.manage' } },
      { path: 'marketing/suivi', component: () => import('../views/gestion/marketing/Tracking.vue'), meta: { title: 'Marketing', permission: 'store.manage' } },
      { path: 'marketing/relances', component: () => import('../views/gestion/marketing/Campaigns.vue'), meta: { platform: 'whatsapp_campaigns', title: 'Relances WhatsApp', permission: 'store.manage' } },
      { path: 'marketing/fidelite', component: () => import('../views/gestion/marketing/Loyalty.vue'), meta: { platform: 'loyalty', title: 'Fidélité', permission: 'store.manage' } },
      { path: 'marketing/avis', component: () => import('../views/gestion/marketing/Reviews.vue'), meta: { platform: 'reviews', title: 'Avis clients', permission: 'store.manage' } },
      { path: 'live', component: () => import('../views/gestion/marketing/Lives.vue'), meta: { platform: 'live', title: 'Vendre en live', permission: 'orders.manage' } },
      { path: 'marketing/live/:id?', redirect: (to) => `/gestion/live${to.params.id ? `/${to.params.id}` : ''}` },
      { path: 'live/:id', component: () => import('../views/gestion/marketing/LiveRoom.vue'), props: true, meta: { platform: 'live', title: 'Live', permission: 'orders.manage' } },
      { path: 'live/:id/afficher', component: () => import('../views/gestion/marketing/LiveShow.vue'), props: true, meta: { platform: 'live_screens', title: 'Écran du live', permission: 'orders.manage' } },
      { path: 'live/:id/cartes', component: () => import('../views/gestion/marketing/LiveCards.vue'), props: true, meta: { platform: 'live_screens', title: 'Cartes du live', permission: 'orders.manage' } },
      { path: 'caisse', component: () => import('../views/gestion/Cash.vue'), meta: { title: 'Caisse', permissionAny: ['finance.view', 'cash.manage'] } },
      { path: 'caisse/vente', component: () => import('../views/gestion/CounterSale.vue'), meta: { title: 'Vente au comptoir', permission: 'cash.manage' } },
      { path: 'caisse/livreurs', component: () => import('../views/gestion/couriers/CashDesk.vue'), meta: { title: 'Argent des livreurs', permissionAny: ['couriers.manage', 'finance.view'] } },
      { path: 'caisse/a-recevoir', component: () => import('../views/gestion/shipping/CarrierPayouts.vue'), meta: { title: 'À recevoir des entreprises', permission: 'carriers.manage' } },
      { path: 'statistiques', component: () => import('../views/gestion/Stats.vue'), meta: { title: 'Statistiques', permissionAny: ['finance.view', 'orders.view'] } },
      { path: 'paiements', component: () => import('../views/gestion/payments/Payments.vue'), meta: { platform: 'online_payment', title: 'Paiement en ligne', permission: 'payments.manage' } },
      { path: 'whatsapp', component: () => import('../views/gestion/messaging/WhatsApp.vue'), meta: { title: 'WhatsApp', permission: 'store.manage' } },
      { path: 'textes-messages', component: () => import('../views/gestion/messaging/MessageTexts.vue'), meta: { title: 'Textes des messages', permission: 'store.manage' } },
      { path: 'abonnement', component: () => import('../views/gestion/Billing.vue'), meta: { title: 'Abonnement', permission: 'billing.manage' } },
      { path: 'materiel', component: () => import('../views/gestion/Materiel.vue'), meta: { title: 'Matériel', permission: 'billing.manage', feature: 'materiel' } },
      { path: 'mes-boutiques', component: () => import('../views/gestion/group/MyStores.vue'), meta: { title: 'Vue d\'ensemble', owner: true } },
      { path: 'mes-boutiques/statistiques', component: () => import('../views/gestion/group/GroupStats.vue'), meta: { title: 'Vue d\'ensemble', owner: true } },
      { path: 'mes-boutiques/caisse', component: () => import('../views/gestion/group/GroupCash.vue'), meta: { title: 'Vue d\'ensemble', owner: true } },
      { path: 'mes-boutiques/livreurs', component: () => import('../views/gestion/group/GroupCouriers.vue'), meta: { title: 'Vue d\'ensemble', owner: true } },
      { path: 'equipe', component: () => import('../views/gestion/Team.vue'), meta: { title: 'Équipe', permission: 'team.manage' } },
      { path: 'parametres', component: () => import('../views/gestion/Settings.vue'), meta: { title: 'Informations de l\'espace marchand' } },
      { path: 'demarrage', component: () => import('../views/gestion/SetupGuide.vue'), meta: { title: 'Bien démarrer' } },
      { path: 'alertes', component: () => import('../views/gestion/AlertSettings.vue'), meta: { title: 'Alertes', permission: 'store.manage' } },
      { path: 'compte', component: () => import('../views/gestion/Account.vue'), meta: { title: 'Mon compte' } },
    ],
  },
  { path: '/gestion/:any(.*)*', redirect: '/gestion' },
]

// admin.jibiat-marchand.com : console du super administrateur
const consoleRoutes = [
  { path: '/console/connexion', component: () => import('../views/console/Login.vue'), meta: { guest: true, title: 'Connexion' } },
  { path: '/console/mot-de-passe-oublie', component: () => import('../views/auth/ForgotPassword.vue'), props: { loginPath: '/console/connexion' }, meta: { guest: true, title: 'Mot de passe oublié' } },
  {
    path: '/console',
    component: () => import('../layouts/ConsoleLayout.vue'),
    meta: { auth: true },
    children: [
      { path: '', component: () => import('../views/console/Dashboard.vue'), meta: { title: 'Tableau de bord' } },
      { path: 'boutiques', component: () => import('../views/console/Stores.vue'), meta: { title: 'Espaces marchands' } },
      { path: 'boutiques/:id(\\d+)', component: () => import('../views/console/StoreDetail.vue'), props: true, meta: { title: 'Espace marchand' } },
      { path: 'paiements', component: () => import('../views/console/Payments.vue'), meta: { title: 'Paiements' } },
      { path: 'whatsapp', component: () => import('../views/console/WhatsApp.vue'), meta: { title: 'WhatsApp' } },
      { path: 'factures', component: () => import('../views/console/Invoices.vue'), meta: { title: 'Factures' } },
      { path: 'parrainage', component: () => import('../views/console/Referrals.vue'), meta: { title: 'Parrainage' } },
      { path: 'offres', component: () => import('../views/console/Plans.vue'), meta: { title: 'Offres' } },
      { path: 'fonctions', component: () => import('../views/console/Features.vue'), meta: { title: 'Fonctions' } },
      { path: 'materiel', component: () => import('../views/console/Materiel.vue'), meta: { title: 'Matériel' } },
      { path: 'cles', component: () => import('../views/console/AccessKeys.vue'), meta: { title: 'Clés d\'accès' } },
      { path: 'ia', component: () => import('../views/console/AiUsage.vue'), meta: { title: 'Assistant IA' } },
      { path: 'compte', component: () => import('../views/console/Account.vue'), meta: { title: 'Mon compte' } },
      { path: 'journal', component: () => import('../views/console/Journal.vue'), meta: { title: 'Journal' } },
      { path: 'equipe', component: () => import('../views/console/Team.vue'), meta: { title: 'Équipe' } },
      { path: 'sante', component: () => import('../views/console/Health.vue'), meta: { title: 'Santé de la plateforme' } },
      { path: 'utilisateurs', component: () => import('../views/console/Users.vue'), meta: { title: 'Utilisateurs' } },
    ],
  },
  { path: '/:any(.*)*', redirect: '/console' },
]

const router = createRouter({
  history: createWebHistory(),
  routes: boot.zone === 'store' ? storeRoutes : boot.zone === 'console' ? consoleRoutes : platformRoutes,
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach(async (to) => {
  if (boot.zone === 'console') {
    const console_ = useConsoleStore()
    if (!console_.loaded) await console_.fetch()
    if (to.matched.some((r) => r.meta.auth) && !console_.admin) return '/console/connexion'
    if (to.meta.guest && console_.admin) return '/console'
    return true
  }
  if (boot.zone !== 'store') return true

  const auth = useAuthStore()
  if (!auth.loaded) await auth.fetch()

  const courierApp = to.path.startsWith('/livreur')
  const home = auth.profile?.role?.value === 'courier' ? '/livreur' : '/gestion'

  if (to.matched.some((r) => r.meta.auth) && !auth.isAuthenticated) {
    const login = courierApp ? '/livreur/connexion' : '/gestion/connexion'
    return { path: login, query: !['/gestion', '/livreur'].includes(to.path) ? { retour: to.fullPath } : {} }
  }
  if (to.meta.guest && auth.isAuthenticated && !to.path.startsWith('/invitation')) {
    return courierApp && auth.profile?.courier ? '/livreur' : home
  }
  // Un livreur n'a que son application ; l'application livreur demande une fiche livreur
  if (to.path.startsWith('/gestion') && home === '/livreur') return '/livreur'
  if (to.matched.some((r) => r.meta.courier) && !auth.profile?.courier) return '/gestion'
  if (to.meta.permissionAny && !to.meta.permissionAny.some((p) => auth.can(p))) {
    return '/gestion'
  }
  if (to.meta.permission && !auth.can(to.meta.permission)) {
    return '/gestion'
  }
  if (to.meta.owner && !auth.profile?.is_owner) return '/gestion'
  // Fonction masquée dans la console (ou en test pour d'autres boutiques)
  if (to.meta.platform && !platformOn(auth, to.meta.platform)) return '/gestion'
  return true
})

/** Fonction allumée pour la boutique (« a|b » : l'une ou l'autre). Profil sans liste : tout est visible. */
export function platformOn(auth, keys) {
  const features = auth.profile?.features
  return !features || keys.split('|').some((k) => features[k] !== false)
}

router.afterEach((to) => {
  const store = boot.zone === 'console' ? 'Console Jibiat Marchand' : useAuthStore().store?.name ?? boot.store?.name
  document.title = [to.meta.title, store ?? 'Jibiat Marchand'].filter(Boolean).join(' · ')
})

// Nouvelle version mise en ligne pendant que la page était ouverte : les fichiers des pages pas encore visitées
// ont changé de nom (et ceux de plus d'une semaine n'existent plus). Sans cela, le clic dans le menu ne fait rien
// jusqu'au rafraîchissement. On recharge donc la page demandée (une seule fois par minute, jamais en boucle).
const RELOAD_KEY = 'reloaded-for-new-version'
function reloadForNewVersion(path) {
  try {
    const last = Number(sessionStorage.getItem(RELOAD_KEY) || 0)
    if (Date.now() - last < 60000) return false
    sessionStorage.setItem(RELOAD_KEY, String(Date.now()))
  } catch {
    // Stockage indisponible : on recharge quand même
  }
  window.location.assign(path)
  return true
}
const isStaleChunk = (error) => /dynamically imported module|Importing a module script failed|error loading dynamically imported module|Failed to load module script/i.test(error?.message || '')

router.onError((error, to) => {
  if (isStaleChunk(error) && reloadForNewVersion(to?.fullPath || window.location.pathname)) return
  console.error(error)
})

export default router
