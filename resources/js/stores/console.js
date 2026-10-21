import { defineStore } from 'pinia'
import http from '../lib/http'

// Session de la console (admin.jibiat-marchand.com)
export const useConsoleStore = defineStore('console', {
  state: () => ({ admin: null, loaded: false, pendingPayments: 0 }),
  actions: {
    async fetch() {
      try {
        this.admin = (await http.get('/console/auth/me')).data.data
      } catch (error) {
        if (![401, 403].includes(error.response?.status)) throw error
        this.admin = null
      } finally {
        this.loaded = true
      }
    },
    // Paiements Wave à valider : pastille du menu
    async refreshPending() {
      try {
        this.pendingPayments = (await http.get('/console/payments/pending')).data.data.length
      } catch {
        // Pastille seulement : une erreur passagère ne bloque rien
      }
    },
    async logout() {
      try {
        await http.post('/console/auth/logout')
      } finally {
        this.admin = null
      }
    },
  },
})
