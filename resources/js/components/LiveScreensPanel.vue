<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import Modal from './Modal.vue'
import http, { errorMessage, fieldErrors } from '../lib/http'
import { boot } from '../lib/boot'

/**
 * Live en images, dans l'écran du live : écrans reliés (télé, tablette, ordinateur), ce qu'ils montrent (article
 * choisi, défilement, « Comment commander »), mode présentation (partage d'écran TikTok) et cartes à enregistrer.
 */
const props = defineProps({ live: { type: Object, required: true } })
const emit = defineEmits(['update'])

const screens = ref([])
const pairing = ref(false)
const form = ref({ code: '', name: '' })
const errors = ref({})
const error = ref('')
const busy = ref(false)
const copied = ref('')
let timer

async function load() {
  try {
    screens.value = (await http.get('/lives/screens')).data.data
  } catch {
    // affichage secondaire
  }
}
onMounted(() => {
  load()
  timer = setInterval(load, 15000)
})
onUnmounted(() => clearInterval(timer))

async function pair() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    await http.post('/lives/screens/pair', form.value)
    pairing.value = false
    form.value = { code: '', name: '' }
    await load()
  } catch (e) {
    errors.value = fieldErrors(e)
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

// Lien direct pour l'ordinateur (TikTok LIVE Studio, OBS) ou PRISM : à coller une fois comme « page web »
async function copyLink(screen = null) {
  try {
    const url = screen?.url ?? (await http.post('/lives/screens/link')).data.data.url
    await navigator.clipboard?.writeText(url)
    copied.value = url
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  }
}

async function remove(screen) {
  if (!window.confirm(`Retirer « ${screen.name} » ? Il n'affichera plus votre live.`)) return
  await http.delete(`/lives/screens/${screen.id}`)
  await load()
}

const MODES = [['product', 'Article choisi'], ['scroll', 'Défilement'], ['how', 'Commander']]
async function setMode(mode) {
  try {
    emit('update', (await http.post(`/lives/${props.live.id}/display`, { mode })).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  }
}
const screenHost = String(boot.platformUrl ?? `https://${boot.domain}`).replace(/^https?:\/\//, '').replace(/\/$/, '')
</script>

<template>
  <section class="card space-y-3 p-4">
    <div class="flex items-center justify-between gap-2">
      <h3 class="font-extrabold">Live en images</h3>
      <span v-if="screens.some((s) => s.online)" class="text-xs font-semibold text-emerald-700">● {{ screens.filter((s) => s.online).length }} écran(s) allumé(s)</span>
    </div>
    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

    <!-- Ce que montrent les écrans et le mode présentation -->
    <div v-if="live.status === 'live'" class="grid grid-cols-3 gap-1 rounded-xl bg-slate-100 p-1 text-xs font-bold">
      <button v-for="[value, label] in MODES" :key="value" type="button" class="rounded-lg py-2" :class="(live.display_mode ?? 'product') === value ? 'bg-white shadow-sm' : 'text-slate-600'" @click="setMode(value)">{{ label }}</button>
    </div>

    <div class="grid gap-2 sm:grid-cols-3">
      <RouterLink :to="`/gestion/live/${live.id}/presentation`" class="rounded-xl border border-slate-200 p-3 text-sm hover:border-emerald-600">
        <b class="block">📱 Mode présentation</b><span class="text-slate-500">À partager à l'écran dans TikTok, votre visage en bas</span>
      </RouterLink>
      <RouterLink :to="`/gestion/live/${live.id}/cartes`" class="rounded-xl border border-slate-200 p-3 text-sm hover:border-emerald-600">
        <b class="block">🖼️ Cartes du live</b><span class="text-slate-500">Une image par article, pour la galerie et WhatsApp</span>
      </RouterLink>
      <button type="button" class="rounded-xl border border-slate-200 p-3 text-left text-sm hover:border-emerald-600" @click="pairing = true">
        <b class="block">📺 Relier un écran</b><span class="text-slate-500">Télé ou tablette derrière vous, qui change toute seule</span>
      </button>
    </div>

    <ul v-if="screens.length" class="divide-y divide-slate-100 text-sm">
      <li v-for="s in screens" :key="s.id" class="flex items-center gap-2 py-2">
        <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="s.online ? 'bg-emerald-500' : 'bg-slate-300'" />
        <span class="min-w-0 flex-1 truncate">{{ s.name }} <span class="text-xs text-slate-500">· {{ s.online ? 'allumé' : 'éteint' }}</span></span>
        <button type="button" class="text-xs font-semibold text-emerald-700" @click="copyLink(s)">Copier le lien</button>
        <button type="button" class="text-xs text-red-600" @click="remove(s)">Retirer</button>
      </li>
    </ul>
    <p class="text-xs text-slate-500">
      Sur ordinateur (TikTok LIVE Studio, OBS) ou dans PRISM : ajoutez une « page web » avec
      <button type="button" class="font-semibold text-emerald-700 underline" @click="copyLink()">le lien de l'écran</button>.
      <span v-if="copied" class="block text-emerald-700">Lien copié ✓ {{ copied }}</span>
    </p>

    <Modal v-if="pairing" title="Relier un écran" @close="pairing = false">
      <form class="space-y-3" @submit.prevent="pair">
        <p class="text-sm text-slate-600">Sur la télé, la tablette ou l'ordinateur, ouvrez <b>{{ screenHost }}/ecran</b> : un code à 4 chiffres s'affiche. Tapez-le ici.</p>
        <input v-model="form.code" class="input text-center text-3xl font-black tracking-[.5em]" inputmode="numeric" maxlength="4" placeholder="····" autocomplete="off" required />
        <p v-if="errors.code" class="text-sm text-red-600">{{ errors.code }}</p>
        <input v-model="form.name" class="input" maxlength="60" placeholder="Nom de l'écran (ex. Télé salon)" />
        <button class="btn-brand w-full" :disabled="busy || form.code.length !== 4">{{ busy ? 'Liaison…' : 'Relier' }}</button>
        <p class="text-center text-xs text-slate-500">Astuce : fond sombre et luminosité un peu baissée, la caméra filme mieux.</p>
      </form>
    </Modal>
  </section>
</template>
