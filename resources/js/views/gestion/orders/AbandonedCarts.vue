<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'

const carts = ref(null)
const error = ref('')

async function load() {
  try {
    carts.value = (await http.get('/carts/abandoned')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

// Le vendeur ouvre WhatsApp avec le message prêt ; la relance est notée
async function remind(cart) {
  window.open(cart.whatsapp_url, '_blank', 'noopener')
  await http.post(`/carts/${cart.id}/recovery`)
  cart.recovery_sent_at = new Date().toISOString()
}
</script>

<template>
  <div class="space-y-4">
    <RouterLink to="/gestion/commandes" class="text-sm text-slate-500 hover:text-emerald-700">← Commandes</RouterLink>
    <p class="text-sm text-slate-600">
      Clients qui ont laissé leur numéro et des articles dans leur panier sans commander (7 derniers jours).
      Si le WhatsApp de la boutique est connecté avec la relance activée, ils reçoivent un message automatique une heure après.
    </p>
    <Alert v-if="error">{{ error }}</Alert>

    <div v-if="carts" class="card">
      <ul class="divide-y divide-slate-100">
        <li v-for="cart in carts" :key="cart.id" class="flex flex-wrap items-start gap-3 px-4 py-3 text-sm">
          <span class="min-w-0 flex-1">
            <span class="block font-medium">{{ cart.name || 'Client' }} · {{ cart.phone }}</span>
            <span class="block text-slate-600">{{ cart.items.join(', ') }}</span>
            <span class="block text-xs text-slate-500">
              Dernière visite le {{ formatDateTime(cart.last_activity_at) }}
              <template v-if="cart.recovery_sent_at"> · relancé le {{ formatDateTime(cart.recovery_sent_at) }}</template>
            </span>
          </span>
          <span class="w-24 text-right font-semibold">{{ money(cart.subtotal) }}</span>
          <button class="btn-secondary px-3 py-1.5" @click="remind(cart)">💬 {{ cart.recovery_sent_at ? 'Relancer encore' : 'Relancer' }}</button>
        </li>
        <li v-if="!carts.length" class="px-4 py-6 text-sm text-slate-500">Aucun panier abandonné.</li>
      </ul>
    </div>
  </div>
</template>
