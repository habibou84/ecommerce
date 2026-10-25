<script setup>
import { onMounted, ref, watch } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'
import { formatDateTime } from '../../lib/format'

// Journal de la console : chaque action de l'équipe (connexions, réglages, espaces marchands), pour repérer un accès anormal
const LABELS = {
  console_login: 'Connexion à la console',
  console_account: 'Mon compte modifié',
  suspend: 'Espace marchand suspendu',
  reactivate: 'Espace marchand réactivé',
  extend_trial: 'Essai prolongé',
  offer: 'Offre accordée',
  plan_updated: 'Offre modifiée',
  support_session: 'Session de support ouverte',
  billing_settings: 'Réglages de paiement',
  billing_mail_settings: 'Envoi des e-mails réglé',
  billing_alert_test: 'Alerte d\'essai',
  billing_wave_qr: 'QR code Wave ajouté',
  billing_wave_qr_removed: 'QR code Wave retiré',
  billing_wave_received: 'Paiement Wave confirmé',
  billing_wave_missing: 'Paiement Wave non reçu',
  referral_paid: 'Commission de parrainage payée',
  referral_settings_updated: 'Réglages du parrainage',
  support_whatsapp_connected: 'WhatsApp du support relié',
  support_whatsapp_disconnected: 'WhatsApp du support déconnecté',
}
const FILTERS = [['', 'Tout'], ['console_', 'Connexions et compte'], ['billing_', 'Paiements'], ['support', 'Support']]

const rows = ref(null)
const filter = ref('')
const error = ref('')
async function load() {
  try {
    rows.value = (await http.get('/console/journal', { params: { action: filter.value || undefined } })).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)
watch(filter, load)
</script>

<template>
  <div class="space-y-4">
    <div>
      <h2 class="text-xl font-semibold">Journal</h2>
      <p class="text-sm text-slate-600">Les 200 dernières actions de l'équipe. Une connexion que vous ne reconnaissez pas ? Changez votre mot de passe dans Mon compte.</p>
    </div>
    <Alert v-if="error">{{ error }}</Alert>
    <div class="flex flex-wrap gap-2">
      <button v-for="[value, label] in FILTERS" :key="value" class="rounded-xl px-3 py-1.5 text-sm font-semibold" :class="filter === value ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200'" @click="filter = value">{{ label }}</button>
    </div>
    <div class="card divide-y divide-slate-100 text-sm">
      <p v-if="rows && !rows.length" class="p-4 text-slate-500">Rien pour l'instant.</p>
      <div v-for="r in rows ?? []" :key="r.id" class="flex flex-wrap items-baseline gap-x-3 gap-y-1 p-3">
        <span class="w-36 shrink-0 text-slate-500">{{ formatDateTime(r.at) }}</span>
        <span class="font-semibold">{{ LABELS[r.action] ?? r.action }}</span>
        <RouterLink v-if="r.store" :to="`/console/boutiques/${r.store.id}`" class="text-emerald-800 hover:underline">{{ r.store.name }}</RouterLink>
        <span class="ml-auto text-xs text-slate-500">{{ r.admin }} · {{ r.ip }}</span>
        <span v-if="r.data?.device" class="w-full truncate text-xs text-slate-400">{{ r.data.device }}</span>
      </div>
    </div>
  </div>
</template>
