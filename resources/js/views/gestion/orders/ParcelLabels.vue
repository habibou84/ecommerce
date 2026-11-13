<script setup>
import { computed, onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import QrScanner from '../../../components/QrScanner.vue'
import http, { download, errorMessage } from '../../../lib/http'
import { formatDateTime } from '../../../lib/format'
import { displayCode } from '../../../lib/parcelLabels'
import { useAuthStore } from '../../../stores/auth'
import { useRouter } from 'vue-router'

// Ventes › Étiquettes : étiquettes pré-imprimées qui restent, nouveau lot à imprimer, colis scanné pour ouvrir sa commande
const auth = useAuthStore()
const router = useRouter()
const data = ref(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const format = ref('')
const quantity = ref(100)
const scanning = ref(false)
const canManage = computed(() => auth.can('orders.manage'))

async function load() {
  try {
    data.value = (await http.get('/parcel-labels')).data.data
    format.value ||= data.value.formats[0]?.code ?? ''
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function createBatch() {
  busy.value = true
  error.value = ''
  try {
    const res = (await http.post('/parcel-labels/batches', { format: format.value, quantity: quantity.value })).data.data
    data.value = res.overview
    await download(`/parcel-labels/batches/${res.id}/pdf`, 'etiquettes.pdf')
    notice.value = `${quantity.value} étiquettes prêtes : imprime le PDF.`
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function found(input) {
  scanning.value = false
  error.value = ''
  const code = displayCode(input)
  if (!code) {
    error.value = 'Ce n\'est pas un code d\'étiquette (exemple : JM-7K4Q-29XB).'
    return
  }
  try {
    const res = (await http.get('/parcel-labels/lookup', { params: { code } })).data.data
    if (res.order_id) router.push(`/gestion/commandes/${res.order_id}`)
    else notice.value = `L'étiquette ${res.code} n'est pas encore utilisée : ouvre une commande et scanne-la pour la relier.`
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-4">
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="notice" tone="success">{{ notice }}</Alert>
    <p v-if="!data" class="text-sm text-slate-500">Chargement…</p>
    <template v-else>
      <section class="card p-4">
        <p class="text-lg font-extrabold">Il te reste {{ data.free }} étiquette{{ data.free > 1 ? 's' : '' }}</p>
        <p class="text-sm text-slate-600">{{ data.used }} déjà reliée{{ data.used > 1 ? 's' : '' }} à une commande. Colle une étiquette sur le colis, puis scanne-la depuis la commande.</p>
        <div v-if="data.free + data.used" class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" :style="{ width: `${(data.free / (data.free + data.used)) * 100}%` }" /></div>
        <button type="button" class="btn-secondary mt-3 w-full" @click="scanning = true">📷 Scanner un colis pour ouvrir sa commande</button>
      </section>

      <section v-if="canManage && data.formats.length" class="card space-y-3 p-4">
        <h3 class="font-extrabold">Nouveau lot à imprimer</h3>
        <div class="grid grid-cols-4 gap-2">
          <button v-for="q in data.quantities" :key="q" type="button" class="rounded-xl py-2 font-bold" :class="quantity === q ? 'bg-emerald-700 text-white' : 'border border-field'" @click="quantity = q">{{ q }}</button>
        </div>
        <div class="space-y-1 text-sm">
          <label v-for="f in data.formats" :key="f.code" class="flex items-center gap-2"><input v-model="format" type="radio" :value="f.code"> <span :class="{ 'font-bold': format === f.code }">{{ f.label }}</span></label>
        </div>
        <p class="text-xs text-slate-500">Mini 40 × 30 : imprimante d'étiquettes (rouleau) ou feuilles d'autocollants A4 (45 par feuille). Chaque étiquette a un code unique, à ta boutique.</p>
        <button type="button" class="btn-brand w-full" :disabled="busy || !format" @click="createBatch">⬇ Imprimer les {{ quantity }} étiquettes</button>
      </section>

      <section v-if="data.batches.length" class="card p-4">
        <h3 class="font-extrabold">Lots imprimés</h3>
        <ul class="mt-2 divide-y divide-slate-100 text-sm">
          <li v-for="b in data.batches" :key="b.id" class="flex items-center justify-between gap-2 py-2">
            <span class="min-w-0"><b>{{ b.quantity }}</b> · {{ b.format_label }}<span class="block text-xs text-slate-500">{{ formatDateTime(b.created_at) }} · {{ b.used }} utilisée{{ b.used > 1 ? 's' : '' }}</span></span>
            <button type="button" class="shrink-0 text-sm font-semibold text-emerald-700" @click="download(`/parcel-labels/batches/${b.id}/pdf`, 'etiquettes.pdf')">Réimprimer</button>
          </li>
        </ul>
      </section>
    </template>
    <QrScanner v-if="scanning" title="Vise le QR du colis" @result="found" @close="scanning = false" />
  </div>
</template>
