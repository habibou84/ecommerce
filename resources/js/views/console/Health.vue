<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'
import { formatDateTime } from '../../lib/format'

// Santé de la plateforme : ce qui doit tourner tourne-t-il ? Lecture seule, sauf relancer ou effacer une tâche en échec.
const health = ref(null)
const error = ref('')
async function load() {
  try {
    health.value = (await http.get('/console/health')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function act(uuid, action) {
  try {
    health.value = (await http.post(`/console/health/failed-jobs/${uuid}`, { action })).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
const gb = (bytes) => `${(bytes / 1024 ** 3).toFixed(1)} Go`
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center gap-3">
      <h2 class="text-xl font-semibold">Santé de la plateforme</h2>
      <button class="btn-ghost ml-auto" @click="load">Actualiser</button>
    </div>
    <Alert v-if="error">{{ error }}</Alert>

    <div v-if="health" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <div class="card p-4 text-sm">
        <p class="font-semibold" :class="health.scheduler.ok ? 'text-emerald-700' : 'text-red-700'">{{ health.scheduler.ok ? '✓' : '✗' }} Tâches planifiées</p>
        <p class="text-slate-600">{{ health.scheduler.last_run ? `Dernier passage le ${formatDateTime(health.scheduler.last_run)}` : 'Aucun passage enregistré' }}</p>
        <p v-if="!health.scheduler.ok" class="mt-1 text-xs text-red-700">Arrêtées : alertes, relances et rapports ne partent plus. Vérifiez la tâche cron du serveur.</p>
      </div>
      <div class="card p-4 text-sm">
        <p class="font-semibold" :class="health.queue.failed ? 'text-amber-800' : 'text-emerald-700'">{{ health.queue.failed ? '!' : '✓' }} File d'attente</p>
        <p class="text-slate-600">{{ health.queue.pending ?? '—' }} en attente · {{ health.queue.failed }} en échec</p>
        <p v-if="health.queue.oldest_pending" class="text-xs text-slate-500">Plus ancienne : {{ formatDateTime(health.queue.oldest_pending) }}</p>
      </div>
      <div class="card p-4 text-sm">
        <p class="font-semibold" :class="health.disk && health.disk.used_percent > 80 ? 'text-red-700' : 'text-emerald-700'">{{ health.disk && health.disk.used_percent > 80 ? '✗' : '✓' }} Espace disque</p>
        <p v-if="health.disk" class="text-slate-600">{{ health.disk.used_percent }} % utilisé · {{ gb(health.disk.free) }} libres sur {{ gb(health.disk.total) }}</p>
      </div>
      <div class="card p-4 text-sm">
        <p class="font-semibold" :class="health.mail.ready ? 'text-emerald-700' : 'text-red-700'">{{ health.mail.ready ? '✓' : '✗' }} E-mails</p>
        <p class="text-slate-600">{{ health.mail.gmail ? `Gmail : ${health.mail.gmail}` : health.mail.ready ? 'Serveur d\'e-mail réglé' : 'Aucun envoi d\'e-mail réglé' }}</p>
        <RouterLink v-if="!health.mail.gmail" to="/console/paiements" class="text-xs text-emerald-700 underline">Connecter Gmail</RouterLink>
      </div>
      <div class="card p-4 text-sm">
        <p class="font-semibold">{{ health.sms.ready ? '✓' : '–' }} SMS · {{ health.whatsapp.connected ? '✓' : '–' }} WhatsApp du support</p>
        <p class="text-slate-600">{{ health.sms.ready ? 'SMS actifs' : 'Pas de fournisseur de SMS' }} · {{ health.whatsapp.connected ? 'WhatsApp relié' : 'WhatsApp non relié' }}</p>
      </div>
      <div class="card p-4 text-sm">
        <p class="font-semibold">Versions</p>
        <p class="text-slate-600">PHP {{ health.versions.php }} · Laravel {{ health.versions.laravel }} · {{ health.versions.environment }}</p>
      </div>
    </div>

    <section v-if="health?.failed_jobs.length" class="card divide-y divide-slate-100 text-sm">
      <p class="p-4 font-semibold">Tâches en échec (les plus récentes)</p>
      <div v-for="j in health.failed_jobs" :key="j.uuid" class="flex flex-wrap items-start gap-x-4 gap-y-1 p-4">
        <div class="min-w-0 flex-1">
          <p class="font-semibold">{{ j.job }} <span class="font-normal text-slate-500">· {{ formatDateTime(j.failed_at) }}</span></p>
          <p class="break-words text-xs text-slate-600">{{ j.error }}</p>
        </div>
        <button class="btn-ghost" @click="act(j.uuid, 'retry')">Relancer</button>
        <button class="text-slate-500 underline" @click="act(j.uuid, 'forget')">Effacer</button>
      </div>
    </section>
  </div>
</template>
