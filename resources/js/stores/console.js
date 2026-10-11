import { defineStore } from 'pinia'
import http from '../lib/http'

// Session de la console (admin.jibiat-marchand.com)
export const useConsoleStore = defineStore('console', {
  state: () => ({ admin: null, loaded: false }),
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
    async logout() {
      try {
        await http.post('/console/auth/logout')
      } finally {
        this.admin = null
      }
    },
  },
})
