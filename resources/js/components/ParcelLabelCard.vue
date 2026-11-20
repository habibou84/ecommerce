<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import QrScanner from './QrScanner.vue'
import http, { errorMessage } from '../lib/http'
import { displayCode, flushLabels, isNetworkError, pendingFor, queueLabel } from '../lib/parcelLabels'

// Fiche commande : étiquette pré-imprimée collée sur le colis, scannée (ou tapée) pour la relier à la commande
const props = defineProps({ order: { type: Object, required: true }, canManage: { type: Boolean, default: false } })
const labels = ref(props.order.parcel_labels ?? [])
const pending = ref(pendingFor(props.order.id))
const scanning = ref(false)
const error = ref('')
const notice = ref('')
const busy = ref(false)

async function attach(input) {
  scanning.value = false
  error.value = ''
  notice.value = ''
  const code = displayCode(input)
  if (!code) {
    error.value = 'Ce n\'est pas un code d\'étiquette (exemple : JM-7K4Q-29XB).'
    return
  }
  busy.value = true
  try {
    labels.value = (await http.post(`/orders/${props.order.id}/parcel-labels`, { code })).data.data.labels
    notice.value = `✓ Étiquette ${code} reliée`
  } catch (e) {
    if (isNetworkError(e)) {
      queueLabel(props.order.id, code)
      pending.value = pendingFor(props.order.id)
      notice.value = `Pas de réseau : ${code} sera reliée dès que la connexion revient.`
    } else {
      error.value = errorMessage(e)
    }
  } finally {
    busy.value = false
  }
}

async function detach(label) {
  if (!confirm(`Détacher l'étiquette ${label.code} de cette commande ?`)) return
  busy.value = true
  try {
    labels.value = (await http.delete(`/orders/${props.order.id}/parcel-labels/${label.id}`)).data.data.labels
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function sync() {
  if (!pending.value.length) return
  const refused = await flushLabels()
  pending.value = pendingFor(props.order.id)
  if (refused.length) error.value = refused.join(' ')
  try {
    labels.value = (await http.get(`/orders/${props.order.id}`)).data.data.parcel_labels ?? labels.value
  } catch {
    // reste affiché tel quel
  }
}
onMounted(() => {
  sync()
  window.addEventListener('online', sync)
})
onUnmounted(() => window.removeEventListener('online', sync))
</script>

<template>
  <section v-if="order.parcel_labels_enabled || labels.length" class="card space-y-2 p-4">
    <h3 class="font-semibold">🏷️ Étiquette du colis</h3>
    <p v-if="!labels.length && !pending.length" class="text-sm text-slate-600">Collez une étiquette Jibiat sur le colis, puis scannez-la.</p>
    <div v-for="l in labels" :key="l.id" class="flex items-center justify-between gap-2 rounded-xl bg-emerald-50 px-3 py-2 text-sm">
      <span class="font-semibold text-emerald-900">✓ {{ l.code }}</span>
      <button v-if="canManage" type="button" class="text-xs font-semibold text-slate-600 underline" :disabled="busy" @click="detach(l)">Détacher</button>
    </div>
    <p v-for="c in pending" :key="c" class="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900">⏳ {{ c }} : en attente de réseau</p>
    <p v-if="notice" class="text-sm font-semibold text-emerald-800">{{ notice }}</p>
    <p v-if="error" class="rounded-xl bg-orange-50 px-3 py-2 text-sm text-orange-900">{{ error }}</p>
    <button v-if="canManage && order.parcel_labels_enabled" type="button" class="btn-brand w-full" :disabled="busy" @click="scanning = true">📷 {{ labels.length ? 'Scanner une autre étiquette' : 'Scanner l\'étiquette' }}</button>
    <QrScanner v-if="scanning" @result="attach" @close="scanning = false" />
  </section>
</template>
