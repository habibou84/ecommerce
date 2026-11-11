<script setup>
import { onMounted, onUnmounted, ref } from 'vue'

// Lecture d'un QR avec la caméra arrière : BarcodeDetector quand le navigateur l'a (Android), sinon jsQR (iPhone).
// « Taper le code » reste toujours possible (pas de caméra, autorisation refusée, étiquette abîmée).
const emit = defineEmits(['result', 'close'])
defineProps({ title: { type: String, default: 'Vise le QR de l\'étiquette' } })

const video = ref(null)
const failed = ref('')
const typing = ref(false)
const typed = ref('')
let stream = null
let timer = null
let detector = null
let jsQR = null
let canvas = null

async function start() {
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false })
  } catch {
    failed.value = 'Caméra indisponible : autorise la caméra pour ce site, ou tape le code.'
    typing.value = true
    return
  }
  video.value.srcObject = stream
  await video.value.play().catch(() => {})
  if ('BarcodeDetector' in window) {
    try {
      detector = new window.BarcodeDetector({ formats: ['qr_code'] })
    } catch {
      detector = null
    }
  }
  if (!detector) jsQR = (await import('jsqr')).default
  canvas = document.createElement('canvas')
  timer = setInterval(scan, 250)
}

async function scan() {
  const v = video.value
  if (!v || v.readyState < 2) return
  try {
    let text = null
    if (detector) {
      const codes = await detector.detect(v)
      text = codes[0]?.rawValue ?? null
    } else {
      const w = Math.min(v.videoWidth, 720)
      const h = Math.round((v.videoHeight * w) / v.videoWidth)
      canvas.width = w
      canvas.height = h
      const ctx = canvas.getContext('2d', { willReadFrequently: true })
      ctx.drawImage(v, 0, 0, w, h)
      text = jsQR(ctx.getImageData(0, 0, w, h).data, w, h, { inversionAttempts: 'dontInvert' })?.data ?? null
    }
    if (text) {
      stop()
      navigator.vibrate?.(60)
      emit('result', text)
    }
  } catch {
    // image suivante
  }
}

function stop() {
  clearInterval(timer)
  timer = null
  stream?.getTracks().forEach((t) => t.stop())
  stream = null
}

function submitTyped() {
  if (!typed.value.trim()) return
  stop()
  emit('result', typed.value.trim())
}

onMounted(start)
onUnmounted(stop)
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[70] bg-black text-white">
      <video ref="video" class="absolute inset-0 h-full w-full object-cover" playsinline muted />
      <div class="pointer-events-none absolute left-1/2 top-[42%] h-56 w-56 -translate-x-1/2 -translate-y-1/2 rounded-3xl border-4 border-emerald-400 shadow-[0_0_0_2000px_rgba(0,0,0,0.45)]" />
      <p class="absolute inset-x-0 top-[max(1.5rem,env(safe-area-inset-top))] px-14 text-center text-base font-extrabold">{{ title }}</p>
      <button type="button" class="absolute right-3 top-[max(1rem,env(safe-area-inset-top))] grid h-10 w-10 place-items-center rounded-full bg-white/15 text-xl" aria-label="Fermer" @click="stop(); emit('close')">✕</button>
      <div class="absolute inset-x-4 bottom-[max(1.5rem,env(safe-area-inset-bottom))]">
        <p v-if="failed" class="mb-2 rounded-xl bg-amber-500/90 p-3 text-sm font-semibold text-black">{{ failed }}</p>
        <form v-if="typing" class="flex gap-2 rounded-2xl bg-white p-2" @submit.prevent="submitTyped">
          <input v-model="typed" class="input min-w-0 flex-1 uppercase text-slate-900" placeholder="JM-7K4Q-29XB" autocomplete="off" autocapitalize="characters" aria-label="Code de l'étiquette" autofocus>
          <button type="submit" class="btn-brand shrink-0">OK</button>
        </form>
        <button v-else type="button" class="block w-full rounded-2xl bg-white/15 py-3 font-bold" @click="typing = true">⌨️ Taper le code</button>
      </div>
    </div>
  </Teleport>
</template>
