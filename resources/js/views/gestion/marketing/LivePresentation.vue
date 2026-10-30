<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import LiveDisplay from '../../../components/LiveDisplay.vue'
import http, { errorMessage } from '../../../lib/http'

/**
 * Mode présentation : la page à partager à l'écran dans un live TikTok (Jeux mobiles / partage d'écran). L'article
 * en grand en haut, la place libre en bas pour la bulle du visage ; on glisse pour changer d'article (les écrans
 * reliés suivent), appui long pour « Comment commander ». Rien de privé n'y apparaît.
 */
const props = defineProps({ id: { type: [String, Number], required: true } })
const router = useRouter()
const data = ref(null)
const error = ref('')
const started = ref(false)
const KEY = 'jibiat-presentation-face'
const face = ref(readFace())
const checks = ref([])
const CHECKS = [
  ['dnd', '« Ne pas déranger » activé', 'Sinon vos notifications et messages WhatsApp passent dans le live.'],
  ['battery', 'Téléphone chargé ou branché', ''],
  ['light', 'Luminosité au maximum', ''],
]
const FACES = [['left', 'En bas à gauche'], ['right', 'En bas à droite'], ['none', 'Sans visage']]
let timer
let wake

function readFace() {
  try {
    return localStorage.getItem(KEY) || 'left'
  } catch {
    return 'left'
  }
}
function chooseFace(value) {
  face.value = value
  try {
    localStorage.setItem(KEY, value)
  } catch {
    // sans importance
  }
}

async function load() {
  try {
    data.value = (await http.get(`/lives/${props.id}/display`)).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(() => {
  load()
  timer = setInterval(load, 3000)
})
onUnmounted(() => {
  clearInterval(timer)
  wake?.release?.()
})

const live = computed(() => data.value?.live ?? null)
const items = computed(() => live.value?.items ?? [])
const current = ref(null) // article montré, sans attendre le serveur
const currentId = computed(() => current.value ?? live.value?.featured_item_id ?? items.value[0]?.id ?? null)

async function start() {
  started.value = true
  try {
    wake = await navigator.wakeLock?.request('screen')
  } catch {
    // sans importance
  }
  if (!live.value?.featured_item_id && items.value[0]) show(items.value[0].id)
}

async function show(itemId) {
  current.value = itemId
  if (data.value?.live) data.value.live.mode = 'product'
  try {
    await http.post(`/lives/${props.id}/feature`, { item_id: itemId })
  } catch (e) {
    error.value = errorMessage(e)
  }
}
function move(delta) {
  if (!items.value.length) return
  const index = Math.max(0, items.value.findIndex((i) => i.id === currentId.value))
  show(items.value[(index + delta + items.value.length) % items.value.length].id)
}
async function toggleHow() {
  const mode = live.value?.mode === 'how' ? 'product' : 'how'
  if (data.value?.live) data.value.live.mode = mode
  try {
    await http.post(`/lives/${props.id}/display`, { mode })
  } catch (e) {
    error.value = errorMessage(e)
  }
}

// Glisser à gauche / à droite, appui long
let touch = null
let press
function down(e) {
  const p = e.touches?.[0] ?? e
  touch = { x: p.clientX, y: p.clientY, at: Date.now() }
  clearTimeout(press)
  press = setTimeout(() => {
    touch = null
    toggleHow()
  }, 650)
}
function up(e) {
  clearTimeout(press)
  if (!touch) return
  const p = e.changedTouches?.[0] ?? e
  const dx = p.clientX - touch.x
  if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(p.clientY - touch.y)) move(dx < 0 ? 1 : -1)
  touch = null
}
function cancel() {
  clearTimeout(press)
}
function keys(e) {
  if (e.key === 'ArrowRight') move(1)
  if (e.key === 'ArrowLeft') move(-1)
}
onMounted(() => window.addEventListener('keydown', keys))
onUnmounted(() => window.removeEventListener('keydown', keys))
</script>

<template>
  <!-- Avant de partager l'écran -->
  <div v-if="!started" class="min-h-screen bg-slate-50 px-4 py-6">
    <div class="mx-auto max-w-md space-y-3">
      <button type="button" class="text-sm text-emerald-700" @click="router.push(`/gestion/live/${id}`)">← Retour au live</button>
      <h1 class="text-xl font-extrabold">Mode présentation</h1>
      <p class="text-sm text-slate-600">La page à partager à l'écran dans votre live TikTok : l'article en grand en haut, votre visage en bas. Aucune commande, aucun numéro ni montant n'y apparaît.</p>
      <p v-if="error" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
      <section class="card space-y-1 p-4">
        <h2 class="font-semibold">Avant de partager l'écran</h2>
        <label v-for="[key, label, hint] in CHECKS" :key="key" class="flex items-start gap-2 py-1 text-sm">
          <input v-model="checks" type="checkbox" :value="key" class="mt-0.5 h-4 w-4 accent-emerald-700" />
          <span>{{ label }}<span v-if="hint" class="block text-xs text-slate-500">{{ hint }}</span></span>
        </label>
      </section>
      <section class="card p-4">
        <h2 class="mb-2 font-semibold">Place de votre visage</h2>
        <div class="grid grid-cols-3 gap-2">
          <button v-for="[value, label] in FACES" :key="value" type="button" class="rounded-xl border-2 p-2 text-xs font-semibold" :class="face === value ? 'border-emerald-600 bg-emerald-50 text-emerald-800' : 'border-slate-200 text-slate-600'" @click="chooseFace(value)">
            <span class="relative mb-1 block h-16 rounded-lg bg-slate-900">
              <span class="absolute inset-x-1.5 top-1.5 h-[46%] rounded bg-orange-500" />
              <span v-if="value !== 'none'" class="absolute bottom-1.5 h-5 w-5 rounded-full bg-slate-200" :class="value === 'left' ? 'left-1.5' : 'right-1.5'" />
            </span>
            {{ label }}
          </button>
        </div>
      </section>
      <section class="card p-4 text-sm text-slate-600">
        Ensuite, dans TikTok : <b>LIVE</b> › <b>Jeux mobiles</b> (partage d'écran) ; revenez ici. Glissez pour changer d'article, <b>appui long</b> pour « Comment commander ».
      </section>
      <button type="button" class="btn-brand w-full py-3 text-base" :disabled="!live" @click="start">▶ Démarrer la présentation</button>
      <p v-if="data && !live" class="text-center text-sm text-amber-800">Lancez d'abord le live depuis sa page.</p>
    </div>
  </div>

  <!-- Présentation (partagée à l'écran) -->
  <div v-else class="fixed inset-0 touch-none select-none bg-slate-950" @touchstart.passive="down" @touchend="up" @touchmove.passive="cancel" @mousedown="down" @mouseup="up">
    <LiveDisplay :data="data" :item-id="currentId" :free-bottom="face === 'none' ? 0 : 0.2" />
    <div v-if="face !== 'none'" class="pointer-events-none absolute inset-x-0 bottom-0 h-[20%] border-t border-dashed border-slate-700/60" />
    <button type="button" class="absolute right-2 top-2 rounded-full bg-white/10 px-3 py-1 text-xs text-white/60" @mousedown.stop @touchstart.stop @click="started = false">✕</button>
  </div>
</template>
