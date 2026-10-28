<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import Alert from '../Alert.vue'
import http, { errorMessage } from '../../lib/http'
import { formatDateTime } from '../../lib/format'

// Rattrapage des commerçants inscrits avant les messages d'accueil : un message par commerçant, selon sa boutique
const data = ref(null)
const busy = ref(false)
const error = ref('')
const open = ref(null)
let timer

const STATE = { idle: 'Pas encore lancé', running: 'En cours d\'envoi', paused: 'En pause', done: 'Terminé' }
const TEMPLATE_STATUS = { PENDING: 'en vérification chez Meta', APPROVED: 'approuvé ✓', REJECTED: 'refusé par Meta', PAUSED: 'en pause chez Meta', DISABLED: 'désactivé par Meta', ERROR: 'erreur à la création' }

async function load() {
  try {
    data.value = (await http.get('/console/whatsapp/catchup')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(() => {
  load()
  // Avancement relu chaque minute pendant l'envoi
  timer = setInterval(() => data.value?.state === 'running' && load(), 60000)
})
onUnmounted(() => clearInterval(timer))

async function act(action) {
  if (action === 'start' && !window.confirm(`Envoyer le message à ${data.value.total} commerçant(s) ? L'envoi part doucement (au plus ${data.value.daily_limit} par jour, jamais entre 21 h et 7 h) et peut être mis en pause.`)) return
  busy.value = true
  error.value = ''
  try {
    data.value = (await http.post(`/console/whatsapp/catchup/${action}`)).data.data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

const needsTemplates = () => !data.value.qr && Object.values(data.value.groups).some((g) => g.template_status !== 'APPROVED')
</script>

<template>
  <section v-if="data" class="card max-w-3xl space-y-3 p-4 text-sm">
    <div class="flex flex-wrap items-center gap-2">
      <h3 class="flex-1 text-base font-semibold">Rattrapage des commerçants déjà inscrits</h3>
      <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="data.state === 'running' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700'">{{ STATE[data.state] }}</span>
    </div>
    <p class="text-slate-600">
      Un seul message par commerçant inscrit depuis plus de 3 jours qui n'a jamais reçu les messages d'accueil, selon sa boutique.
      Pas à ceux qui ont répondu STOP ni aux boutiques fermées. Jamais deux fois au même commerçant.
    </p>
    <Alert v-if="error">{{ error }}</Alert>

    <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
      <li v-for="(g, key) in data.groups" :key="key" class="p-3">
        <div class="flex flex-wrap items-center gap-2">
          <span class="flex-1 font-medium">{{ g.label }} : <strong>{{ g.count }}</strong> commerçant(s)</span>
          <span v-if="!data.qr" class="text-xs text-slate-500">Modèle {{ g.template }} : {{ TEMPLATE_STATUS[g.template_status] ?? 'pas encore créé' }}</span>
          <button type="button" class="text-xs text-emerald-700 underline" @click="open = open === key ? null : key">{{ open === key ? 'Masquer' : 'Voir le message' }}</button>
        </div>
        <pre v-if="open === key" class="mt-2 whitespace-pre-wrap rounded-lg bg-emerald-50 p-3 font-sans text-slate-800">{{ g.preview }}</pre>
      </li>
    </ul>

    <p v-if="data.state !== 'idle'" class="text-slate-700">
      Envoyés : <strong>{{ data.sent }}</strong><template v-if="data.failed"> · en échec : {{ data.failed }}</template> · restant : {{ data.remaining }}<template v-if="data.started_at"> · lancé le {{ formatDateTime(data.started_at) }}</template>
    </p>
    <Alert v-if="needsTemplates()" tone="warning">
      Avec la connexion officielle, ces messages partent par des modèles « marketing » approuvés par Meta (facturés par Meta à chaque envoi). Un groupe dont le modèle n'est pas encore approuvé attend.
    </Alert>

    <div class="flex flex-wrap gap-2">
      <button v-if="data.state !== 'running'" class="btn-brand" :disabled="busy || !data.total" @click="act('start')">{{ data.state === 'paused' ? 'Reprendre l\'envoi' : `Envoyer à ${data.total} commerçant(s)` }}</button>
      <button v-else class="btn-ghost" :disabled="busy" @click="act('pause')">Mettre en pause</button>
      <button v-if="!data.qr" class="btn-ghost" :disabled="busy" @click="act('templates')">Créer les modèles chez Meta</button>
    </div>
  </section>
</template>
