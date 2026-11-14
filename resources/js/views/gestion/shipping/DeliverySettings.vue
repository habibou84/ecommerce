<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import DeliveryTabs from '../../../components/DeliveryTabs.vue'
import http, { errorMessage } from '../../../lib/http'

// Réglages des livraisons faites par les livreurs de la boutique (code du client, position pendant la tournée)
const settings = ref(null)
const error = ref('')
const saved = ref(false)

onMounted(async () => {
  try {
    settings.value = (await http.get('/couriers')).data.settings
  } catch (e) {
    error.value = errorMessage(e)
  }
})

async function setting(key, value) {
  error.value = ''
  saved.value = false
  try {
    settings.value = (await http.patch('/couriers/settings', { [key]: value })).data.data
    saved.value = true
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="space-y-4">
    <DeliveryTabs />
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="saved" tone="success">Enregistré.</Alert>

    <section v-if="settings" class="card space-y-3 p-4 text-sm">
      <h3 class="font-semibold">Livraisons par mes livreurs</h3>
      <label class="flex items-start gap-2">
        <input type="checkbox" class="mt-0.5" :checked="settings.require_delivery_code" @change="setting('require_delivery_code', $event.target.checked)" />
        <span>Code du client à la livraison <span class="block text-slate-500">Le client reçoit un code sur sa page de suivi et le donne au livreur : la livraison ne se valide qu'avec ce code.</span></span>
      </label>
      <label class="flex items-start gap-2">
        <input type="checkbox" class="mt-0.5" :checked="settings.courier_tracking" @change="setting('courier_tracking', $event.target.checked)" />
        <span>Voir la position des livreurs pendant leur tournée <span class="block text-slate-500">Leur dernière position s'affiche dans Mes livreurs, quand ils sont disponibles et que l'application est ouverte. Prévenez-les : l'application le leur indique.</span></span>
      </label>
    </section>

    <section class="card p-4 text-sm">
      <h3 class="mb-2 font-semibold">Ailleurs dans Livraisons</h3>
      <ul class="space-y-1.5">
        <li><RouterLink to="/gestion/livraisons/automatisation" class="text-emerald-700 underline">Automatisation</RouterLink> : qui livre quelle commande, demande automatique de la livraison.</li>
        <li><RouterLink to="/gestion/livraisons/paie" class="text-emerald-700 underline">Paie des livreurs</RouterLink> : règles de paie, primes, relevés.</li>
        <li><RouterLink to="/gestion/livraisons/zones" class="text-emerald-700 underline">Zones et jours de livraison</RouterLink> : heure limite, jours sans livraison.</li>
        <li><RouterLink to="/gestion/alertes" class="text-emerald-700 underline">Alertes</RouterLink> : colis pas ramassés, argent qui traîne chez un livreur.</li>
      </ul>
    </section>
  </div>
</template>
