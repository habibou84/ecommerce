import { createRouter, createWebHistory } from 'vue-router'
import { boot } from '../lib/boot'
import { useAuthStore } from '../stores/auth'
import { useConsoleStore } from '../stores/console'

// jibiat-marchand.com : création de boutique, retrouver ses boutiques
const platformRoutes = [
  { path: '/creer-ma-boutique', component: () => import('../views/platform/Signup.vue') },
  { path: '/connexion', component: () => import('../views/platform/Login.vue') },
  // Fenêtre Facebook de la connexion WhatsApp, pour toutes les boutiques
  { path: '/whatsapp/facebook', component: () => import('../views/platform/WhatsAppFacebook.vue') },
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
      { path: 'argent', component: () => import('../views/courier/Money.vue'), meta: { title: 'Mon argent' } },
    ],
  },
  { path: '/livreur/:any(.*)*', redirect: '/livreur' },
  {
    path: '/gestion',
    component: () => import('../layouts/GestionLayout.vue'),
    meta: { auth: true },
    children: [
      { path: '', component: () => import('../views/gestion/Dashboard.vue'), meta: { title: 'Accueil' } },
      { path: 'commandes', component: () => import('../views/gestion/orders/OrderList.vue'), meta: { title: 'Commandes', permission: 'orders.view' } },
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
      { path: 'livraisons/zones', component: () => import('../views/gestion/DeliveryZones.vue'), meta: { title: 'Zones et tarifs', permission: 'store.manage' } },
      { path: 'livraisons/qui-livre-quoi', component: () => import('../views/gestion/shipping/ShippingRules.vue'), meta: { title: 'Qui livre quoi', permission: 'shipments.manage' } },
      { path: 'expeditions', redirect: '/gestion/livraisons' },
      { path: 'expeditions/transporteurs', redirect: '/gestion/livraisons/entreprises' },
      { path: 'expeditions/regles', redirect: '/gestion/livraisons/qui-livre-quoi' },
      { path: 'expeditions/reversements', redirect: '/gestion/caisse/a-recevoir' },
      { path: 'livreurs', redirect: '/gestion/livraisons/livreurs' },
      { path: 'livreurs/caisse', redirect: '/gestion/caisse/livreurs' },
      { path: 'boutique-en-ligne', component: () => import('../views/gestion/online-store/OnlineStore.vue'), meta: { title: 'Boutique en ligne', permission: 'store.manage' } },
      { path: 'boutique-en-ligne/pages', component: () => import('../views/gestion/online-store/Pages.vue'), meta: { title: 'Boutique en ligne', permission: 'store.manage' } },
      { path: 'boutique-en-ligne/domaine', component: () => import('../views/gestion/online-store/Domains.vue'), meta: { title: 'Boutique en ligne', permission: 'store.manage' } },
      { path: 'boutique-en-ligne/menus', component: () => import('../views/gestion/online-store/Menus.vue'), meta: { title: 'Boutique en ligne', permission: 'store.manage' } },
      { path: 'marketing', component: () => import('../views/gestion/marketing/Promotions.vue'), meta: { title: 'Promotions', permission: 'store.manage' } },
      { path: 'marketing/suivi', component: () => import('../views/gestion/marketing/Tracking.vue'), meta: { title: 'Marketing', permission: 'store.manage' } },
      { path: 'caisse', component: () => import('../views/gestion/Cash.vue'), meta: { title: 'Caisse', permissionAny: ['finance.view', 'cash.manage'] } },
      { path: 'caisse/vente', component: () => import('../views/gestion/CounterSale.vue'), meta: { title: 'Vente au comptoir', permission: 'cash.manage' } },
      { path: 'caisse/livreurs', component: () => import('../views/gestion/couriers/CashDesk.vue'), meta: { title: 'Argent des livreurs', permissionAny: ['couriers.manage', 'finance.view'] } },
      { path: 'caisse/a-recevoir', component: () => import('../views/gestion/shipping/CarrierPayouts.vue'), meta: { title: 'À recevoir des entreprises', permission: 'carriers.manage' } },
      { path: 'statistiques', component: () => import('../views/gestion/Stats.vue'), meta: { title: 'Statistiques', permissionAny: ['finance.view', 'orders.view'] } },
      { path: 'paiements', component: () => import('../views/gestion/payments/Payments.vue'), meta: { title: 'Paiement en ligne', permission: 'payments.manage' } },
      { path: 'whatsapp', component: () => import('../views/gestion/messaging/WhatsApp.vue'), meta: { title: 'WhatsApp', permission: 'store.manage' } },
      { path: 'abonnement', component: () => import('../views/gestion/Billing.vue'), meta: { title: 'Abonnement', permission: 'billing.manage' } },
      { path: 'mes-boutiques', component: () => import('../views/gestion/MyStores.vue'), meta: { title: 'Mes boutiques', owner: true } },
      { path: 'equipe', component: () => import('../views/gestion/Team.vue'), meta: { title: 'Équipe', permission: 'team.manage' } },
      { path: 'parametres', component: () => import('../views/gestion/Settings.vue'), meta: { title: 'Ma boutique' } },
      { path: 'compte', component: () => import('../views/gestion/Account.vue'), meta: { title: 'Mon compte' } },
    ],
  },
  { path: '/gestion/:any(.*)*', redirect: '/gestion' },
]

// admin.jibiat-marchand.com : console du super administrateur
const consoleRoutes = [
  { path: '/console/connexion', component: () => import('../views/console/Login.vue'), meta: { guest: true, title: 'Connexion' } },
  {
    path: '/console',
    component: () => import('../layouts/ConsoleLayout.vue'),
    meta: { auth: true },
    children: [
      { path: '', component: () => import('../views/console/Dashboard.vue'), meta: { title: 'Tableau de bord' } },
      { path: 'boutiques', component: () => import('../views/console/Stores.vue'), meta: { title: 'Boutiques' } },
      { path: 'boutiques/:id(\\d+)', component: () => import('../views/console/StoreDetail.vue'), props: true, meta: { title: 'Boutique' } },
      { path: 'factures', component: () => import('../views/console/Invoices.vue'), meta: { title: 'Factures' } },
      { path: 'offres', component: () => import('../views/console/Plans.vue'), meta: { title: 'Offres' } },
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
  return true
})

router.afterEach((to) => {
  const store = boot.zone === 'console' ? 'Console Jibiat Marchand' : boot.store?.name
  document.title = [to.meta.title, store ?? 'Jibiat Marchand'].filter(Boolean).join(' · ')
})

export default router
