import { defineStore } from 'pinia'
import http from '../lib/http'
import { chooseStore, chosenStore } from '../lib/boutique'
import { savedProfile, saveProfile } from '../lib/courierOffline'

// Session de gestion de la boutique de l'adresse
export const useAuthStore = defineStore('auth', {
  state: () => ({
    profile: null, // { user, store, role, permissions, is_owner, other_stores }
    loaded: false,
    // Dernier changement de boutique (message de confirmation)
    switchedTo: null,
  }),

  getters: {
    user: (s) => s.profile?.user ?? null,
    store: (s) => s.profile?.store ?? null,
    isAuthenticated: (s) => !!s.profile,
    can: (s) => (permission) => (s.profile?.permissions ?? []).includes(permission),
  },

  actions: {
    async fetch() {
      try {
        const { data } = await http.get('/auth/me')
        this.profile = data.data
        if (this.profile?.courier) saveProfile(this.profile)
      } catch (error) {
        // Livreur sans réseau : l'application s'ouvre avec son dernier profil
        if (error.isAxiosError && !error.response && location.pathname.startsWith('/livreur') && savedProfile()) {
          this.profile = savedProfile()
          return
        }
        if (![401, 403].includes(error.response?.status)) throw error
        // Boutique choisie qui n'est plus accessible : retour à la boutique de l'adresse
        if (error.response?.status === 403 && chosenStore()) {
          chooseStore(null)
          return this.fetch()
        }
        this.profile = null
      } finally {
        this.loaded = true
      }
    },

    async login(phone, password) {
      const { data } = await http.post('/auth/login', { phone, password })
      this.profile = data.data
      // La connexion peut ouvrir une autre boutique de l'adresse (personne qui ne travaille que pour elle)
      chooseStore(data.data.store.id)
      this.loaded = true
    },

    // Changer de boutique sans changer d'adresse ni recharger l'application : même rubrique dans la nouvelle
    // boutique (une page propre à l'ancienne, comme une commande, ramène à sa liste)
    async switchTo(id, path = null) {
      const { default: router } = await import('../router')
      const current = router.currentRoute.value
      chooseStore(id)
      await this.fetch()
      if (!this.profile) return router.replace('/gestion/connexion')
      this.switchedTo = { name: this.store?.name, at: Date.now() }
      const target = path ?? (Object.values(current.params).some((v) => v !== '' && v !== undefined)
        ? current.path.replace(/\/\d+(\/.*)?$/, '')
        : current.path)
      // Le garde du routeur ramène à l'accueil si la personne n'a pas accès à cette rubrique dans la nouvelle boutique
      return router.replace(target)
    },

    async logout() {
      try {
        await http.post('/auth/logout')
      } finally {
        this.profile = null
      }
    },

    setStore(store) {
      if (this.profile) this.profile.store = store
    },
  },
})
