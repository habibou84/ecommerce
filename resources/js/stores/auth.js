import { defineStore } from 'pinia'
import http from '../lib/http'

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
        this.profile = null
      } finally {
        this.loaded = true
      }
    },

    async login(phone, password) {
      const { data } = await http.post('/auth/login', { phone, password })
      this.profile = data.data
      this.loaded = true
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
