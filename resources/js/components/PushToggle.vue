<script setup>
import { onMounted, ref } from 'vue'
import { disableWebPush, enableWebPush, initWebPush, testWebPush, webPush } from '../lib/push'

// Notifications sur cet appareil (navigateur, ou application installée depuis Chrome ou Safari). Dans l'application
// Android, elles sont déjà réglées par le téléphone : ce bloc ne s'affiche pas.
const props = defineProps({ app: { type: String, default: 'gestion' } })
const tested = ref('')
onMounted(initWebPush)

async function test() {
  tested.value = ''
  try {
    const n = await testWebPush(props.app)
    tested.value = n ? 'Notification d\'essai envoyée : elle arrive dans quelques secondes.' : 'Aucun appareil n\'a répondu : désactivez puis réactivez.'
  } catch {
    tested.value = 'Essai impossible pour le moment.'
  }
}
</script>

<template>
  <section v-if="webPush.supported || webPush.iosNeedsInstall" class="space-y-3">
    <h2 class="font-semibold">Notifications sur cet appareil</h2>
    <p class="text-sm text-slate-600">Nouvelles commandes, alertes et missions arrivent même application fermée, avec un son.</p>

    <p v-if="webPush.iosNeedsInstall" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
      Sur iPhone, installez d'abord l'application : dans Safari, touchez Partager puis « Sur l'écran d'accueil ». Ouvrez-la ensuite depuis l'icône et revenez ici.
    </p>
    <p v-else-if="webPush.checked && !webPush.serverEnabled" class="text-sm text-slate-500">Bientôt disponible.</p>
    <template v-else-if="webPush.checked">
      <p v-if="webPush.subscribed" class="text-sm font-medium text-emerald-800">✅ Activées sur cet appareil.</p>
      <p v-else-if="webPush.permission === 'denied'" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
        Les notifications sont bloquées pour ce site : autorisez-les dans les réglages du navigateur (le cadenas à côté de l'adresse), puis réessayez.
      </p>
      <div class="flex flex-wrap gap-2">
        <button v-if="!webPush.subscribed" type="button" class="btn-brand" :disabled="webPush.busy" @click="enableWebPush">{{ webPush.busy ? 'Activation…' : '🔔 Activer les notifications' }}</button>
        <template v-else>
          <button type="button" class="btn-secondary" @click="test">Envoyer un essai</button>
          <button type="button" class="btn-ghost" :disabled="webPush.busy" @click="disableWebPush">Désactiver</button>
        </template>
      </div>
      <p v-if="webPush.error" class="text-sm text-red-700">{{ webPush.error }}</p>
      <p v-if="tested" class="text-sm text-slate-600">{{ tested }}</p>
    </template>
  </section>
</template>
