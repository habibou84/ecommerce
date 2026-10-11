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
  {
    path: '/gestion',
    component: () => import('../layouts/GestionLayout.vue'),
    meta: { auth: true },
    children: [
      { path: '', component: () => import('../views/gestion/Dashboard.vue'), meta: { title: 'Accueil' } },
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

  if (to.matched.some((r) => r.meta.auth) && !auth.isAuthenticated) {
    return { path: '/gestion/connexion', query: to.fullPath !== '/gestion' ? { retour: to.fullPath } : {} }
  }
  if (to.meta.guest && auth.isAuthenticated && !to.path.startsWith('/invitation')) {
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
