<script setup>
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { inApp } from '../lib/app'
import { install, isIos, promptInstall } from '../lib/install'

// Invitation à installer l'application, dès l'ouverture tant qu'elle n'est pas installée (ou après une désinstallation).
// Android, ordinateur : Chrome propose l'installation et on l'affiche aussitôt, avec le bouton « Installer ».
// iPhone : l'installation directe n'existe pas, on montre la marche à suivre (Partager › Sur l'écran d'accueil).
// « Plus tard » la cache 3 jours. Jamais dans l'application Android/iOS, ni sur Messages (qui a sa propre icône).
const props = defineProps({ name: { type: String, default: 'Jibiat Marchand' }, icon: { type: String, default: '/brand/icon-192.png' }, hide: { type: String, default: '/gestion/messages' } })

const KEY = 'jibiat-install-later'
const LATER_DAYS = 3
const route = useRoute()
const ua = typeof navigator === 'undefined' ? '' : navigator.userAgent
// Navigateur intégré (WhatsApp, Facebook, Instagram…) : pas d'écran d'accueil, il faut ouvrir Safari
const iosInApp = /FBAN|FBAV|FB_IAB|Instagram|Line\/|WhatsApp|Snapchat|TikTok|musical_ly/i.test(ua)
const iosChrome = /CriOS|FxiOS|EdgiOS/i.test(ua)

const later = ref((() => {
  try {
    return Number(localStorage.getItem(KEY) || 0) > Date.now()
  } catch {
    return false
  }
})())

const show = computed(() => !inApp && !install.installed && !later.value && !route.path.startsWith(props.hide) && (!!install.prompt || isIos()))

async function installNow() {
  await promptInstall()
}
function postpone() {
  later.value = true
  try {
    localStorage.setItem(KEY, String(Date.now() + LATER_DAYS * 86400000))
  } catch {
    // sans stockage : cachée jusqu'au prochain chargement
  }
}
</script>

<template>
  <div v-if="show" class="fixed inset-x-3 bottom-[max(0.75rem,env(safe-area-inset-bottom))] z-[55] mx-auto max-w-md rounded-2xl border border-slate-200 bg-white p-4 shadow-xl" role="dialog" :aria-label="`Installer ${props.name}`">
    <div class="flex items-start gap-3">
      <img :src="props.icon" alt="" class="h-12 w-12 shrink-0 rounded-xl border border-slate-100">
      <div class="min-w-0 flex-1">
        <p class="explain">Installez {{ props.name }} sur votre téléphone</p>
        <p class="explain-detail">Une icône sur l'écran d'accueil : l'application s'ouvre d'un geste, avec les notifications.</p>
      </div>
    </div>

    <!-- iPhone : la marche à suivre, montrée tout de suite -->
    <template v-if="!install.prompt">
      <ol v-if="iosInApp" class="mt-3 space-y-2.5 text-base">
        <li class="flex gap-3"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-emerald-100 text-sm font-bold text-emerald-900">1</span><span>Touchez <b>⋯</b> ou <b>Partager</b>, puis <b>Ouvrir dans Safari</b>.</span></li>
        <li class="flex gap-3"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-emerald-100 text-sm font-bold text-emerald-900">2</span><span>Dans Safari, touchez <b>Partager</b> puis <b>Sur l'écran d'accueil</b>.</span></li>
      </ol>
      <ol v-else class="mt-3 space-y-2.5 text-base">
        <li class="flex gap-3"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-emerald-100 text-sm font-bold text-emerald-900">1</span>
          <span>Touchez <b>Partager</b>
            <svg class="mx-0.5 inline h-5 w-5 align-[-3px] text-sky-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M8 7l4-4 4 4" /><path d="M6 11H5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1h-1" /></svg>
            {{ iosChrome ? 'en haut à droite' : 'en bas de l\'écran' }}.</span></li>
        <li class="flex gap-3"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-emerald-100 text-sm font-bold text-emerald-900">2</span><span>Faites défiler et choisissez <b>Sur l'écran d'accueil</b>.</span></li>
        <li class="flex gap-3"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-emerald-100 text-sm font-bold text-emerald-900">3</span><span>Touchez <b>Ajouter</b> en haut à droite.</span></li>
      </ol>
      <button type="button" class="btn-ghost mt-3 w-full" @click="postpone">Plus tard</button>
    </template>

    <!-- Android, ordinateur : installation en un geste -->
    <div v-else class="mt-3 grid grid-cols-2 gap-2">
      <button type="button" class="btn-secondary" @click="postpone">Plus tard</button>
      <button type="button" class="btn-brand" @click="installNow">Installer</button>
    </div>
  </div>
</template>
