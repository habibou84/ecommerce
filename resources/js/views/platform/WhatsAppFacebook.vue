<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import AuthShell from '../../components/AuthShell.vue'
import Alert from '../../components/Alert.vue'
import WhatsAppHelp from '../../components/WhatsAppHelp.vue'
import { boot } from '../../lib/boot'
import http from '../../lib/http'
import { connectWhatsApp, facebookDialogUrl, facebookReturnUrl, inAppBrowser, prefersRedirect, prepareFacebook } from '../../lib/facebook'

// Fenêtre Facebook de la connexion WhatsApp, ouverte depuis la plateforme pour toutes les boutiques (Meta n'accepte
// pas les sous-domaines en joker) ; le résultat repart vers la gestion de la boutique qui l'a demandée.
// Ordinateur : fenêtre Facebook par-dessus la page. Téléphone : la page entière part chez Facebook (la pop-up s'y
// ferme avant de démarrer) et revient ici avec le code (?code=…&state=…), puis repart vers la boutique.
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
// Application Meta : toujours celle de la plateforme, donnée par le serveur (jamais celle d'une adresse reçue)
const options = { appId: null, configId: null, version: 'v25.0', coexistence: ['1', 'auto'].includes(source.coexistence) }
const sure = source.coexistence === '1'
// Espace qui recevra la connexion (vérifié par le serveur, affiché en grand avant d'ouvrir Facebook)
const destination = ref(null)
const checking = ref(true)

// Retour de Facebook : accepté seulement s'il a été lancé depuis cette page, dans ce navigateur (un lien Facebook
// fabriqué par quelqu'un d'autre ne peut pas renvoyer le code vers son espace)
const NONCE_KEY = 'wa-fb-nonce'
function readNonce() {
  try {
    return window.localStorage.getItem(NONCE_KEY)
  } catch {
    return null
  }
}
function writeNonce(value) {
  try {
    value ? window.localStorage.setItem(NONCE_KEY, value) : window.localStorage.removeItem(NONCE_KEY)
  } catch {
    // stockage indisponible : le retour de Facebook sera refusé, la fenêtre (ordinateur) reste possible
  }
}

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
// Page d'où l'on vient (même boutique) : « Revenir » y ramène, au lieu des réglages WhatsApp
const from = computed(() => {
  try {
    const url = new URL(String(source.depuis ?? ''))
    return back.value && url.origin === new URL(back.value).origin ? url.href : null
  } catch {
    return null
  }
})
// Ouverte depuis l'application installée : clé sous laquelle déposer le résultat (l'application attend dessous)
const appKey = /^[A-Za-z0-9]{32,64}$/.test(String(source.cle ?? '')) ? String(source.cle) : null
const handedBack = ref(false)
const redirectUri = facebookReturnUrl(boot.platformUrl)
const embeddedBrowser = inAppBrowser()
const copied = ref(false)

onMounted(async () => {
  if (returned && back.value) {
    const expected = readNonce()
    writeNonce(null)
    if (!returned.n || returned.n !== expected) {
      checking.value = false
      return
    }
    // Facebook a renvoyé le code (ou un refus) : on repart aussitôt vers la boutique
    if (route.query.code) {
      goBack({ code: String(route.query.code), redirect_uri: redirectUri, coexistence: sure ? '1' : '0' })
    } else {
      goBack({ erreur: route.query.error_reason === 'user_denied' ? 'Connexion à Facebook annulée.' : 'Facebook n\'a pas terminé la connexion. Recommencez quand vous voulez.' })
    }
    return
  }
  if (back.value) {
    try {
      const params = { retour: back.value, ...(appKey ? { cle: appKey } : {}) }
      const data = (await http.get('/whatsapp/facebook/destination', { params })).data.data
      destination.value = data
      Object.assign(options, { appId: data.app_id, configId: data.config_id, version: data.graph_version || options.version })
    } catch {
      destination.value = null
    }
  }
  checking.value = false
  if (destination.value && options.appId && !prefersRedirect()) prepareFacebook(options)
})

// « Revenir » : la page d'où l'on vient, sans message d'erreur
function leave() {
  window.location.assign(from.value ?? back.value)
}

async function goBack(params) {
  const result = { ...params, ...(from.value ? { depuis: from.value } : {}) }
  if (appKey) {
    try {
      await http.post('/whatsapp/facebook/result', { key: appKey, result })
      handedBack.value = true
      // Fenêtre ouverte par l'application : on la ferme si le téléphone le permet
      window.setTimeout(() => window.close(), 600)
      return
    } catch {
      // Clé expirée : retour classique vers la boutique
    }
  }
  window.location.assign(`${back.value}#${new URLSearchParams({ whatsapp: '1', ...result })}`)
}

// Téléphone : la page entière chez Facebook
function fullPage() {
  const nonce = [...window.crypto.getRandomValues(new Uint8Array(16))].map((b) => b.toString(16).padStart(2, '0')).join('')
  writeNonce(nonce)
  const state = btoa(JSON.stringify({ n: nonce, coexistence: source.coexistence === 'auto' ? 'auto' : (sure ? '1' : '0'), retour: back.value, depuis: from.value, cle: appKey }))
    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
  window.location.assign(facebookDialogUrl(options, redirectUri, state))
}

async function start() {
  busy.value = true
  error.value = ''
  if (prefersRedirect()) return fullPage()
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
  <AuthShell title="Connecte ton numéro WhatsApp sur Meta" subtitle="Connexion officielle, avec ton compte Facebook.">
    <div v-if="handedBack" class="space-y-2 text-center">
      <p class="text-lg font-extrabold">✅ C'est fait !</p>
      <p class="text-[15px] text-slate-700">Ferme cette fenêtre (✕ en haut) : l'application Jibiat Marchand termine la connexion toute seule.</p>
    </div>
    <p v-else-if="checking" class="text-center text-sm text-slate-600">{{ returned ? 'Connexion reçue de Facebook, retour vers votre espace…' : 'Chargement…' }}</p>
    <Alert v-else-if="!back || returned || !destination || !options.appId || !options.configId" tone="error">
      Ce lien n'est pas valable. Revenez dans votre espace marchand (Réglages › WhatsApp) et recommencez.
    </Alert>
    <div v-else class="space-y-4">
      <!-- Navigateur intégré (lien ouvert depuis WhatsApp, Facebook, Instagram…) : la connexion y échoue souvent -->
      <div v-if="embeddedBrowser" class="space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
        <p><b>Ouvrez cette page dans Chrome ou Safari</b> pour connecter WhatsApp : dans le navigateur de WhatsApp, Facebook ou Instagram, la connexion n'aboutit souvent pas.</p>
        <p>Menu ⋮ (ou ⋯) › « Ouvrir dans le navigateur », ou copiez le lien et collez-le dans Chrome.</p>
        <button type="button" class="btn-secondary w-full" @click="copyLink">{{ copied ? 'Lien copié ✓' : 'Copier le lien' }}</button>
      </div>
      <div class="rounded-lg border-2 border-[var(--brand)] bg-brand-soft p-3 text-center">
        <p class="text-sm text-slate-700">Ton numéro WhatsApp sera connecté à :</p>
        <p class="text-lg font-black">{{ destination.name }}</p>
        <p class="text-xs text-slate-600">{{ destination.host }}</p>
      </div>
      <p class="text-sm font-semibold text-red-700">Ce n'est pas ton espace ? Ferme cette page : quelqu'un essaie de récupérer ton WhatsApp.</p>
      <p class="text-[15px] text-slate-700">Facebook s'ouvre et te guide : suis ses étapes jusqu'au bout.</p>
      <Alert v-if="error" tone="error">{{ error }}</Alert>
      <button type="button" class="btn-brand w-full py-4 text-[17px] font-black" :disabled="busy" @click="start">{{ busy ? 'Connexion à Facebook…' : 'Continuer avec Facebook' }}</button>
      <button type="button" class="btn-secondary w-full" @click="leave">Revenir</button>
      <WhatsAppHelp class="text-center" />
    </div>
  </AuthShell>
</template>
