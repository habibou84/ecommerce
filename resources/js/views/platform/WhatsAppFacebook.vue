<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import AuthShell from '../../components/AuthShell.vue'
import Alert from '../../components/Alert.vue'
import { boot } from '../../lib/boot'
import { connectWhatsApp, prepareFacebook } from '../../lib/facebook'

// Fenêtre Facebook de la connexion WhatsApp, ouverte depuis la plateforme pour toutes les boutiques (Meta n'accepte
// pas les sous-domaines en joker) ; le résultat repart vers la gestion de la boutique qui l'a demandée.
const route = useRoute()
const error = ref('')
const busy = ref(false)
const options = { appId: route.query.app, configId: route.query.config, version: route.query.v || 'v20.0', coexistence: route.query.coexistence === '1' }

// Retour seulement vers une boutique de la plateforme (https://{boutique}.jibiat-marchand.com/…)
const back = computed(() => {
  try {
    const url = new URL(String(route.query.retour ?? ''))
    const platform = new URL(boot.platformUrl)
    return url.protocol === platform.protocol && url.hostname.endsWith(`.${platform.hostname}`) ? `${url.origin}${url.pathname}` : null
  } catch {
    return null
  }
})

onMounted(() => {
  if (back.value && options.appId) prepareFacebook(options)
})

function goBack(params) {
  window.location.assign(`${back.value}#${new URLSearchParams({ whatsapp: '1', ...params })}`)
}

async function start() {
  busy.value = true
  error.value = ''
  try {
    const result = await connectWhatsApp(options)
    goBack({ code: result.code, waba_id: result.waba_id, phone_number_id: result.phone_number_id ?? '', coexistence: options.coexistence ? '1' : '0' })
  } catch (e) {
    error.value = e.message
    busy.value = false
  }
}
</script>

<template>
  <AuthShell title="Connecter WhatsApp" subtitle="Avec votre compte Facebook, pour la boutique qui vous a envoyé ici.">
    <Alert v-if="!back || !options.appId || !options.configId" tone="error">
      Ce lien n'est pas valable. Revenez dans la gestion de votre boutique (Réglages › WhatsApp) et recommencez.
    </Alert>
    <div v-else class="space-y-4">
      <p class="text-sm text-slate-600">
        Une fenêtre Facebook s'ouvre et vous guide : compte Business, nom affiché, numéro de la boutique et code reçu par SMS.
        Vous revenez ensuite automatiquement sur votre boutique.
      </p>
      <Alert v-if="error" tone="error">{{ error }}</Alert>
      <button type="button" class="btn-brand w-full" :disabled="busy" @click="start">{{ busy ? 'Fenêtre Facebook ouverte…' : 'Continuer avec Facebook' }}</button>
      <button type="button" class="btn-secondary w-full" @click="goBack({ erreur: 'Connexion interrompue. Vous pouvez recommencer quand vous voulez.' })">Revenir à la boutique</button>
    </div>
  </AuthShell>
</template>
