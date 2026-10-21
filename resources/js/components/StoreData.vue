<script setup>
import { onMounted, onUnmounted, reactive, ref } from 'vue'
import Alert from './Alert.vue'
import Field from './Field.vue'
import Modal from './Modal.vue'
import http, { download, errorMessage } from '../lib/http'
import { formatDateTime } from '../lib/format'
import { useAuthStore } from '../stores/auth'

// Vos données : export complet (archive de fichiers CSV) et fermeture de la boutique, pour le propriétaire
const auth = useAuthStore()
const exports = ref([])
const error = ref('')
const closing = reactive({ open: false, confirmation: '', reason: '', error: '', busy: false, done: false })
let poll = null

async function load() {
  exports.value = (await http.get('/store-data/exports')).data.data
  clearInterval(poll)
  if (exports.value.some((e) => e.status === 'pending')) poll = setInterval(load, 5000)
}
onMounted(load)
onUnmounted(() => clearInterval(poll))

async function requestExport() {
  error.value = ''
  try {
    await http.post('/store-data/exports')
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  }
}

const size = (bytes) => (bytes > 1048576 ? `${(bytes / 1048576).toFixed(1).replace('.', ',')} Mo` : `${Math.max(1, Math.round(bytes / 1024))} Ko`)

async function close() {
  closing.error = ''
  closing.busy = true
  try {
    await http.post('/store-data/close', { confirmation: closing.confirmation, reason: closing.reason || null })
    closing.done = true
  } catch (e) {
    closing.error = errorMessage(e)
  } finally {
    closing.busy = false
  }
}
</script>

<template>
  <section class="card space-y-3 p-4 text-sm sm:p-6">
    <h2 class="font-semibold">Vos données</h2>
    <p class="text-slate-600">Téléchargez toutes les données de votre espace marchand : produits et stock, clients, commandes et articles, paiements, livraisons, dépenses, fournisseurs, avis. Une archive de fichiers qui s'ouvrent dans Excel, gardée 7 jours.</p>
    <Alert v-if="error">{{ error }}</Alert>
    <ul v-if="exports.length" class="divide-y divide-slate-100">
      <li v-for="e in exports" :key="e.id" class="flex items-center gap-2 py-2">
        <span class="flex-1">Export du {{ formatDateTime(e.created_at) }}</span>
        <span v-if="e.status === 'pending'" class="text-slate-500">En préparation…</span>
        <span v-else-if="e.status === 'failed'" class="text-red-600">Échec</span>
        <button v-else class="text-emerald-700 underline" @click="download(`/store-data/exports/${e.id}/download`, 'donnees.zip')">Télécharger ({{ size(e.size) }})</button>
      </li>
    </ul>
    <button class="btn-secondary" :disabled="exports.some((e) => e.status === 'pending')" @click="requestExport">Exporter toutes mes données</button>

    <div class="border-t border-slate-200 pt-3">
      <h3 class="font-semibold text-red-700">Fermer l'espace marchand</h3>
      <p class="text-slate-600">La boutique en ligne et la gestion ferment pour toute l'équipe, l'abonnement s'arrête à la fin de la période payée. Vos données sont conservées : exportez-les avant. Pour rouvrir, contactez le support.</p>
      <button class="btn-ghost mt-2 text-red-600" @click="Object.assign(closing, { open: true, confirmation: '', reason: '', error: '', done: false })">Fermer l'espace marchand…</button>
    </div>

    <Modal v-if="closing.open" title="Fermer l'espace marchand" @close="closing.open = false">
      <div v-if="closing.done" class="space-y-3 text-sm">
        <Alert tone="success">L'espace marchand est fermé. Merci d'avoir utilisé Jibiat Marchand.</Alert>
        <a href="/" class="btn-brand w-full">Terminer</a>
      </div>
      <form v-else class="space-y-4" @submit.prevent="close">
        <Alert v-if="closing.error">{{ closing.error }}</Alert>
        <p class="text-sm">Pour confirmer, tapez le nom de l'espace marchand : <strong>{{ auth.store?.name }}</strong></p>
        <Field id="close-name" label="Nom de l'espace marchand"><input id="close-name" v-model="closing.confirmation" class="input" autocomplete="off" required /></Field>
        <Field id="close-reason" label="Pourquoi fermez-vous ? (facultatif)"><input id="close-reason" v-model="closing.reason" class="input" maxlength="255" /></Field>
        <button class="btn-danger w-full" :disabled="closing.busy || closing.confirmation.trim().toLowerCase() !== (auth.store?.name ?? '').trim().toLowerCase()">Fermer définitivement</button>
      </form>
    </Modal>
  </section>
</template>
