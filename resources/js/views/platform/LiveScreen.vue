<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import LiveDisplay from '../../components/LiveDisplay.vue'
import http from '../../lib/http'

/**
 * Écran du live (jibiat-marchand.com/ecran) : sur une télé, une tablette ou un ordinateur. Sans compte : l'écran
 * affiche un code à 4 chiffres que la vendeuse tape dans l'application, puis montre le live de sa boutique.
 * Le lien direct (/ecran/{jeton}) sert à TikTok LIVE Studio, OBS ou PRISM. L'écran est retenu sur cet appareil.
 */
const props = defineProps({ token: { type: String, default: null } })
const KEY = 'jibiat-live-screen'

const token = ref(props.token ?? read())
const code = ref('')
const data = ref(null)
const offline = ref(false)
let timer
let wake

function read() {
  try {
    return localStorage.getItem(KEY)
  } catch {
    return null
  }
}
function remember(value) {
  try {
    value ? localStorage.setItem(KEY, value) : localStorage.removeItem(KEY)
  } catch {
    // navigateur sans stockage : l'écran redemandera un code
  }
}

async function create() {
  const { data: res } = await http.post('/screens')
  token.value = res.data.token
  code.value = res.data.code
  remember(token.value)
}

async function poll() {
  try {
    if (!token.value) await create()
    const res = (await http.get(`/screens/${token.value}`)).data.data
    offline.value = false
    if (!res.paired) {
      code.value = res.code
      data.value = null
      return
    }
    data.value = res
    remember(token.value)
  } catch (e) {
    // Écran retiré par la boutique : on recommence avec un nouveau code
    if (e?.response?.status === 404) {
      token.value = null
      data.value = null
      remember(null)
      return
    }
    offline.value = true
  }
}

// L'écran reste allumé pendant le live (si le navigateur le permet)
async function keepAwake() {
  try {
    wake = await navigator.wakeLock?.request('screen')
  } catch {
    // sans importance
  }
}
function fullscreen() {
  document.documentElement.requestFullscreen?.().catch(() => {})
  keepAwake()
}

onMounted(() => {
  poll()
  timer = setInterval(poll, 2000)
  keepAwake()
  document.addEventListener('visibilitychange', keepAwake)
})
onUnmounted(() => {
  clearInterval(timer)
  wake?.release?.()
  document.removeEventListener('visibilitychange', keepAwake)
})

const digits = computed(() => (code.value || '····').split(''))
</script>

<template>
  <div class="fixed inset-0 bg-slate-950 text-white" @dblclick="fullscreen">
    <LiveDisplay v-if="data" :data="data" />

    <!-- Pas encore relié : le code à taper dans l'application -->
    <div v-else class="flex h-full flex-col items-center justify-center gap-[3vh] px-6 text-center">
      <p class="text-[3.2vh] font-black text-emerald-400">Jibiat Marchand</p>
      <h1 class="text-[4.6vh] font-extrabold">Écran du live</h1>
      <div class="flex gap-[2vh]">
        <span v-for="(d, i) in digits" :key="i" class="grid h-[18vh] w-[14vh] place-items-center rounded-[2.4vh] bg-white text-[12vh] font-black text-slate-900">{{ d }}</span>
      </div>
      <p class="max-w-[90vh] text-[2.6vh] leading-snug text-slate-300">
        Dans l'application Jibiat Marchand, ouvrez votre live › <b class="text-white">« Afficher mes articles dans le live »</b>, touchez <b class="text-white">« Relier un écran »</b>, puis tapez ce code.
        La prochaine fois, cet écran se reconnecte tout seul.
      </p>
      <button type="button" class="rounded-full bg-white/10 px-5 py-2 text-[2vh] font-semibold" @click="fullscreen">Plein écran</button>
    </div>

    <p v-if="offline" class="fixed bottom-3 left-1/2 -translate-x-1/2 rounded-full bg-amber-500 px-4 py-1.5 text-sm font-bold text-slate-900">Connexion perdue · nouvel essai…</p>
  </div>
</template>
