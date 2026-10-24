<script setup>
import { computed, ref } from 'vue'

/**
 * Bouton « Envoyer le message WhatsApp » (message prérempli). Sur Android, un lien wa.me ouvre toujours l'appli
 * choisie une fois pour toutes (WhatsApp ou WhatsApp Business), même si ce n'est pas celle du bon numéro : on
 * propose donc les deux, chacune ouverte directement (lien « intent » avec le nom de l'appli). Si l'appli n'est pas
 * installée, le lien wa.me prend le relais. Ailleurs (iPhone, ordinateur, application installée), un seul bouton.
 * « Copier le message » sert quand rien ne s'ouvre.
 */
const props = defineProps({
  link: { type: String, required: true }, // https://wa.me/2250700000099?text=…
  text: { type: String, default: '' },
})

const APPS = [
  { id: 'com.whatsapp', label: 'WhatsApp' },
  { id: 'com.whatsapp.w4b', label: 'WhatsApp Business' },
]

const android = /Android/i.test(navigator.userAgent) && !window.Capacitor?.isNativePlatform?.()
const copied = ref(false)

const target = computed(() => {
  try {
    const url = new URL(props.link)
    return { phone: url.pathname.replace(/\D+/g, ''), text: url.searchParams.get('text') ?? props.text }
  } catch {
    return null
  }
})

function intentFor(app) {
  const { phone, text } = target.value
  return `intent://send/?phone=${phone}&text=${encodeURIComponent(text)}`
    + `#Intent;scheme=whatsapp;package=${app};S.browser_fallback_url=${encodeURIComponent(props.link)};end`
}

async function copy() {
  try {
    await navigator.clipboard.writeText(props.text || target.value?.text || '')
    copied.value = true
    setTimeout(() => (copied.value = false), 2500)
  } catch {
    copied.value = false
  }
}
</script>

<template>
  <div class="space-y-2">
    <template v-if="android && target">
      <p class="text-center text-sm font-medium text-slate-700">Le numéro est sur quel WhatsApp ?</p>
      <div class="grid grid-cols-2 gap-2">
        <a v-for="app in APPS" :key="app.id" :href="intentFor(app.id)"
          :class="['flex items-center justify-center gap-1.5 rounded-xl py-3 text-sm font-semibold shadow-sm', app.id === 'com.whatsapp' ? 'bg-[#25D366] text-white hover:bg-[#1ebe5b]' : 'border-2 border-[#25D366] bg-white text-[#128C7E] hover:bg-emerald-50']">
          <span aria-hidden="true">💬</span> {{ app.label }}
        </a>
      </div>
    </template>
    <a v-else :href="link" target="_blank" rel="noopener" class="flex w-full items-center justify-center gap-2 rounded-xl bg-[#25D366] py-3 font-semibold text-white shadow-sm hover:bg-[#1ebe5b]">
      <span aria-hidden="true">💬</span> Envoyer le message WhatsApp
    </a>
    <button v-if="text" type="button" class="mx-auto block text-xs text-slate-500 underline" @click="copy">
      {{ copied ? 'Message copié ✓' : 'Copier le message' }}
    </button>
  </div>
</template>
