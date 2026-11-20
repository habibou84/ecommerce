<script setup>
import { onMounted, ref } from 'vue'
import http, { errorMessage } from '../lib/http'

// Quartiers manquants signalés par les boutiques : à ajouter à la liste commune (habibou84/socle), puis marquer
const reports = ref(null)
const error = ref('')

async function load() {
  try {
    reports.value = (await http.get('/console/place-reports')).data.data
  } catch {
    reports.value = []
  }
}

async function decide(report, status) {
  try {
    await http.put(`/console/place-reports/${report.id}`, { status })
    reports.value = reports.value.filter((r) => r.id !== report.id)
  } catch (e) {
    error.value = errorMessage(e)
  }
}

onMounted(load)
</script>

<template>
  <section class="card space-y-2 p-4 text-sm">
    <h3 class="font-semibold">Quartiers signalés <span v-if="reports" class="font-normal text-slate-500">({{ reports.length }} en attente)</span></h3>
    <p v-if="reports && !reports.length" class="text-slate-500">Aucun quartier en attente.</p>
    <ul v-else-if="reports" class="divide-y">
      <li v-for="r in reports" :key="r.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
        <span><b>{{ r.name }}</b> · {{ r.parent }} <span class="text-slate-500">· {{ r.store || '—' }}</span></span>
        <span class="flex gap-2">
          <button type="button" class="btn-secondary !py-1" @click="decide(r, 'added')">Ajouté</button>
          <button type="button" class="btn-ghost !py-1 text-red-700" @click="decide(r, 'rejected')">Refuser</button>
        </span>
      </li>
    </ul>
    <p v-if="error" class="text-red-600">{{ error }}</p>
    <p class="text-xs text-slate-500">À ajouter dans la liste commune (socle), pour tous.</p>
  </section>
</template>
