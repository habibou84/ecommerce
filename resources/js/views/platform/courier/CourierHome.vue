<script setup>
// jibiat-marchand.com/livreur : lien commun des livreurs. Sans compte : s'inscrire ou se connecter. Connecté :
// son espace (demandes des boutiques, profil) et, s'il livre déjà pour une boutique, son application.
import { onMounted, ref } from 'vue'
import AuthShell from '../../../components/AuthShell.vue'
import CourierSpace from '../../../components/CourierSpace.vue'
import http, { errorMessage } from '../../../lib/http'

const state = ref('loading') // loading | guest | space
const space = ref(null)
const error = ref('')
const opening = ref(false)

onMounted(async () => {
  try {
    space.value = (await http.get('/courier-space')).data.data
    state.value = 'space'
  } catch (e) {
    if (e.response?.status === 401 || e.response?.status === 419) state.value = 'guest'
    else error.value = errorMessage(e)
  }
})

async function openApp() {
  opening.value = true
  try {
    window.location.assign((await http.post('/courier-space/open')).data.data.redirect)
  } catch (e) {
    error.value = errorMessage(e)
    opening.value = false
  }
}
async function logout() {
  await http.post('/courier-space/logout').catch(() => {})
  window.location.assign('/livreur')
}
</script>

<template>
  <AuthShell v-if="state !== 'space'" title="Livreurs Jibiat Marchand" subtitle="Un seul compte pour livrer pour plusieurs boutiques.">
    <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
    <div v-else-if="state === 'guest'" class="space-y-4">
      <ul class="space-y-2 text-sm text-slate-700">
        <li>📦 Les boutiques vous ajoutent avec votre numéro, ou vous trouvent dans l'annuaire.</li>
        <li>✅ Vous acceptez ou refusez chaque boutique.</li>
        <li>💰 Vos courses de toutes vos boutiques au même endroit ; l'argent reste séparé par boutique.</li>
      </ul>
      <RouterLink to="/livreur/inscription" class="btn-brand block w-full text-center">Devenir livreur</RouterLink>
      <RouterLink to="/livreur/connexion" class="btn-secondary block w-full text-center">J'ai déjà un compte</RouterLink>
    </div>
    <p v-else class="text-center text-sm text-slate-500">Chargement…</p>
  </AuthShell>

  <div v-else class="mx-auto min-h-screen max-w-lg space-y-4 bg-slate-50 px-4 pb-10 pt-6">
    <header class="flex items-center gap-2">
      <h1 class="flex-1 font-display text-xl font-bold">Mon espace livreur</h1>
      <button class="text-sm text-slate-500 underline" @click="logout">Se déconnecter</button>
    </header>
    <button v-if="space.stores.length" class="btn-brand w-full py-3 text-base" :disabled="opening" @click="openApp">📦 Ouvrir ma tournée</button>
    <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
    <CourierSpace show-number @changed="(s) => (space = s)" />
  </div>
</template>
