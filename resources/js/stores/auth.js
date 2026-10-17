import { defineStore } from 'pinia'
import http from '../lib/http'
import { chooseStore, chosenStore } from '../lib/boutique'

// Session de gestion de la boutique de l'adresse
export const useAuthStore = defineStore('auth', {
  state: () => ({
    profile: null, // { user, store, role, permissions, is_owner, other_stores }
    loaded: false,
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
      } catch (error) {
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

    // Changer de boutique sans changer d'adresse
    switchTo(id, path = '/gestion') {
      chooseStore(id)
      window.location.assign(path)
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
