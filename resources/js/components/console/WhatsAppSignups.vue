<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../Alert.vue'
import http, { errorMessage } from '../../lib/http'

// Inscription par WhatsApp : interrupteur, lien wa.me « Inscription » et son QR code (site, bio TikTok, pubs),
// combien de personnes atteignent chaque étape sur 30 jours
const data = ref(null)
const error = ref('')
const copied = ref(false)
const STEPS = [
  ['started', 'Ont écrit « Inscription »'],
  ['confirmed', 'Ont confirmé leur numéro'],
  ['name', 'Ont donné le nom de la boutique'],
  ['first_name', 'Ont donné leur prénom'],
  ['live', 'Ont répondu pour le live'],
  ['created', 'Boutique créée'],
  ['photo', 'Ont envoyé une photo'],
]

onMounted(async () => {
  try {
    data.value = (await http.get('/console/whatsapp/signups')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
})

async function toggle() {
  try {
    data.value = (await http.put('/console/whatsapp/signups', { enabled: !data.value.enabled })).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}

async function copy() {
  try {
    await navigator.clipboard.writeText(data.value.link)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    // Le lien reste affiché pour être recopié
  }
}

const width = (key) => `${data.value.funnel.started ? Math.round((data.value.funnel[key] / data.value.funnel.started) * 100) : 0}%`
</script>

<template>
  <section class="card space-y-3 p-4 text-sm">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h3 class="text-base font-bold">Inscription par WhatsApp</h3>
      <label v-if="data" class="flex items-center gap-2 font-semibold"><input type="checkbox" class="h-5 w-5" :checked="data.enabled" @change="toggle" /> Active</label>
    </div>
    <Alert v-if="error">{{ error }}</Alert>
    <p class="text-slate-600">
      La personne écrit « Inscription » à ce numéro : numéro confirmé, nom de la boutique, prénom, live ou pas, récapitulatif, puis lien de connexion
      (15 min) et photo du premier article. « aide » passe la main à l'équipe ; quand vous répondez vous-même, le robot se tait 6 h pour cette personne.
    </p>
    <template v-if="data">
      <div v-if="data.link" class="flex flex-wrap items-center gap-3">
        <img :src="data.qr" alt="QR code du lien d'inscription par WhatsApp" class="h-28 w-28 rounded-lg border border-slate-200" />
        <div class="min-w-0 flex-1 space-y-2">
          <p class="break-all font-mono text-xs">{{ data.link }}</p>
          <button type="button" class="btn-ghost" @click="copy">{{ copied ? '✓ Copié' : 'Copier le lien' }}</button>
          <p class="text-xs text-slate-500">Avec un code : ajoutez-le après le mot, par exemple « Inscription ABC12 » (carte d'accès ou entreprise de livraison qui invite).</p>
        </div>
      </div>
      <div>
        <p class="mb-1 font-semibold">30 derniers jours</p>
        <ul class="space-y-1">
          <li v-for="[key, label] in STEPS" :key="key" class="grid grid-cols-[1fr_auto] items-center gap-2">
            <span class="relative overflow-hidden rounded bg-slate-50 px-2 py-1"><span class="absolute inset-y-0 left-0 bg-emerald-100" :style="{ width: width(key) }" /><span class="relative">{{ label }}</span></span>
            <b class="w-10 text-right">{{ data.funnel[key] }}</b>
          </li>
        </ul>
      </div>
    </template>
  </section>
</template>
