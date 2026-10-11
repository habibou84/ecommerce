import { createRouter, createWebHistory } from 'vue-router'
import { boot } from '../lib/boot'
import { useAuthStore } from '../stores/auth'

// jibiat-marchand.com : création de boutique, retrouver ses boutiques
const platformRoutes = [
  { path: '/creer-ma-boutique', component: () => import('../views/platform/Signup.vue') },
  { path: '/connexion', component: () => import('../views/platform/Login.vue') },
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
      { path: 'confirmation', component: () => import('../views/gestion/orders/ConfirmationQueue.vue'), meta: { title: 'À confirmer', permission: 'orders.confirm' } },
      { path: 'clients', component: () => import('../views/gestion/customers/CustomerList.vue'), meta: { title: 'Clients', permission: 'customers.view' } },
      { path: 'clients/:id(\\d+)', component: () => import('../views/gestion/customers/CustomerDetail.vue'), props: true, meta: { title: 'Client', permission: 'customers.view' } },
      { path: 'zones-de-livraison', component: () => import('../views/gestion/DeliveryZones.vue'), meta: { title: 'Zones de livraison', permission: 'store.manage' } },
      { path: 'catalogue', component: () => import('../views/gestion/catalog/ProductList.vue'), meta: { title: 'Catalogue', permission: 'products.view' } },
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
      { path: 'livreurs', component: () => import('../views/gestion/couriers/Couriers.vue'), meta: { title: 'Livreurs', permission: 'couriers.manage' } },
      { path: 'livreurs/caisse', component: () => import('../views/gestion/couriers/CashDesk.vue'), meta: { title: 'Caisse des livreurs', permissionAny: ['couriers.manage', 'finance.view'] } },
      { path: 'expeditions', component: () => import('../views/gestion/shipping/Shipments.vue'), meta: { title: 'Expéditions', permission: 'shipments.manage' } },
      { path: 'expeditions/transporteurs', component: () => import('../views/gestion/shipping/Carriers.vue'), meta: { title: 'Expéditions', permission: 'carriers.manage' } },
      { path: 'expeditions/reversements', component: () => import('../views/gestion/shipping/CarrierPayouts.vue'), meta: { title: 'Expéditions', permission: 'carriers.manage' } },
      { path: 'boutique-en-ligne', component: () => import('../views/gestion/online-store/OnlineStore.vue'), meta: { title: 'Boutique en ligne', permission: 'store.manage' } },
      { path: 'boutique-en-ligne/pages', component: () => import('../views/gestion/online-store/Pages.vue'), meta: { title: 'Boutique en ligne', permission: 'store.manage' } },
      { path: 'boutique-en-ligne/menus', component: () => import('../views/gestion/online-store/Menus.vue'), meta: { title: 'Boutique en ligne', permission: 'store.manage' } },
      { path: 'equipe', component: () => import('../views/gestion/Team.vue'), meta: { title: 'Équipe', permission: 'team.manage' } },
      { path: 'parametres', component: () => import('../views/gestion/Settings.vue'), meta: { title: 'Paramètres' } },
    ],
  },
  { path: '/gestion/:any(.*)*', redirect: '/gestion' },
]

const router = createRouter({
  history: createWebHistory(),
  routes: boot.zone === 'store' ? storeRoutes : platformRoutes,
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach(async (to) => {
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
  return true
})

router.afterEach((to) => {
  const store = boot.store?.name
  document.title = [to.meta.title, store ?? 'Jibiat Marchand'].filter(Boolean).join(' · ')
})

export default router
