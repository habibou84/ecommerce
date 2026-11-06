<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import AuthShell from '../../components/AuthShell.vue'
import Alert from '../../components/Alert.vue'
import { boot } from '../../lib/boot'
import { connectWhatsApp, facebookDialogUrl, facebookReturnUrl, inAppBrowser, prepareFacebook } from '../../lib/facebook'

// Fenêtre Facebook de la connexion WhatsApp, ouverte depuis la plateforme pour toutes les boutiques (Meta n'accepte
// pas les sous-domaines en joker) ; le résultat repart vers la gestion de la boutique qui l'a demandée.
// Fenêtre Facebook (pop-up) partout, téléphone compris ; en secours « Ouvrir en pleine page » : la page entière part
// chez Facebook et revient ici avec le code (?code=…&state=…), puis repart vers la boutique.
const route = useRoute()
const error = ref('')
const busy = ref(false)

// Retour de Facebook (page entière) : les options et la boutique de retour reviennent dans « state »
const returned = (() => {
  if (!route.query.state) return null
  try {
    return JSON.parse(atob(String(route.query.state).replace(/-/g, '+').replace(/_/g, '/')))
  } catch {
    return null
  }
})()
const source = returned ?? route.query
// coexistence : '1' = numéro gardé dans l'application WhatsApp Business ; 'auto' = Meta le détecte lui-même
const options = { appId: source.app, configId: source.config, version: source.v || 'v25.0', coexistence: ['1', 'auto'].includes(source.coexistence) }
const sure = source.coexistence === '1'

// Retour seulement vers une boutique de la plateforme (https://{boutique}.jibiat-marchand.com/…)
const back = computed(() => {
  try {
    const url = new URL(String(source.retour ?? ''))
    const platform = new URL(boot.platformUrl)
    return url.protocol === platform.protocol && url.hostname.endsWith(`.${platform.hostname}`) ? `${url.origin}${url.pathname}` : null
  } catch {
    return null
  }
})
const redirectUri = facebookReturnUrl(boot.platformUrl)
const embeddedBrowser = inAppBrowser()
// Version téléphone de la page de Meta (navigateur pas en « Version pour ordinateur ») : le choix de la devise de
// la carte bancaire s'y bloque ; la version ordinateur marche
const phoneLayout = /Android|iPhone|iPod|Mobile/i.test(navigator.userAgent)
const copied = ref(false)

onMounted(() => {
  if (returned && back.value) {
    // Facebook a renvoyé le code (ou un refus) : on repart aussitôt vers la boutique
    if (route.query.code) {
      goBack({ code: String(route.query.code), redirect_uri: redirectUri, coexistence: sure ? '1' : '0' })
    } else {
      goBack({ erreur: route.query.error_reason === 'user_denied' ? 'Connexion à Facebook annulée.' : 'Facebook n\'a pas terminé la connexion. Recommencez quand vous voulez.' })
    }
    return
  }
  if (back.value && options.appId) prepareFacebook(options)
})

function goBack(params) {
  window.location.assign(`${back.value}#${new URLSearchParams({ whatsapp: '1', ...params })}`)
}

// Secours : la page entière chez Facebook (fenêtre bloquée ou résultat perdu dans un autre onglet)
function fullPage() {
  const state = btoa(JSON.stringify({ app: options.appId, config: options.configId, v: options.version, coexistence: source.coexistence === 'auto' ? 'auto' : (sure ? '1' : '0'), retour: back.value }))
    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
  window.location.assign(facebookDialogUrl(options, redirectUri, state))
}

async function start() {
  busy.value = true
  error.value = ''
  try {
    const result = await connectWhatsApp(options)
    goBack({ code: result.code, waba_id: result.waba_id, phone_number_id: result.phone_number_id ?? '', coexistence: sure || result.coexistence ? '1' : '0' })
  } catch (e) {
    error.value = e.message
    busy.value = false
  }
}

async function copyLink() {
  try {
    await navigator.clipboard.writeText(window.location.href)
    copied.value = true
  } catch {
    copied.value = false
  }
}
</script>

<template>
  <AuthShell title="Connecter WhatsApp" subtitle="Avec ton compte Facebook.">
    <p v-if="returned && back" class="text-center text-sm text-slate-600">Connexion reçue de Facebook, retour vers votre espace…</p>
    <Alert v-else-if="!back || !options.appId || !options.configId" tone="error">
      Ce lien n'est pas valable. Revenez dans votre espace marchand (Réglages › WhatsApp) et recommencez.
    </Alert>
    <div v-else class="space-y-4">
      <!-- Navigateur intégré (lien ouvert depuis WhatsApp, Facebook, Instagram…) : la connexion y échoue souvent -->
      <div v-if="embeddedBrowser" class="space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
        <p><b>Ouvrez cette page dans Chrome ou Safari</b> pour connecter WhatsApp : dans le navigateur de WhatsApp, Facebook ou Instagram, la connexion n'aboutit souvent pas.</p>
        <p>Menu ⋮ (ou ⋯) › « Ouvrir dans le navigateur », ou copiez le lien et collez-le dans Chrome.</p>
        <button type="button" class="btn-secondary w-full" @click="copyLink">{{ copied ? 'Lien copié ✓' : 'Copier le lien' }}</button>
      </div>
      <!-- Deux consignes, en grand : le reste, Facebook le guide -->
      <div class="space-y-3">
        <p v-if="phoneLayout && !embeddedBrowser" class="flex items-center gap-3 rounded-2xl bg-sky-50 p-4 text-[17px] font-bold leading-snug text-sky-950"><span class="text-3xl" aria-hidden="true">💻</span><span>Chrome ⋮ › « Version pour ordinateur »</span></p>
        <p class="flex items-center gap-3 rounded-2xl bg-amber-50 p-4 text-[17px] font-bold leading-snug text-amber-950"><span class="text-3xl" aria-hidden="true">💳</span><span>Carte : choisis <u>USD</u> ou <u>EUR</u></span></p>
      </div>
      <Alert v-if="error" tone="error">{{ error }}</Alert>
      <button type="button" class="btn-brand w-full py-4 text-[17px] font-black" :disabled="busy" @click="start">{{ busy ? 'Fenêtre Facebook ouverte…' : 'Continuer avec Facebook' }}</button>
      <button v-if="phoneLayout || error" type="button" class="w-full text-center text-sm font-semibold text-slate-500 underline" @click="fullPage">La fenêtre ne s'ouvre pas ? Ouvrir en pleine page</button>
      <button type="button" class="btn-secondary w-full" @click="goBack({ erreur: 'Connexion interrompue. Vous pouvez recommencer quand vous voulez.' })">Revenir</button>
    </div>
  </AuthShell>
</template>
