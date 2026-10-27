<script setup>
import { onMounted, reactive, ref } from 'vue'
import Alert from './Alert.vue'
import http, { errorMessage, fieldErrors } from '../lib/http'

/**
 * Vendre sur WhatsApp : réponses automatiques (désactivées par défaut ; avertissement quand elles partiraient du
 * WhatsApp relié par QR code) et catalogue WhatsApp (lien du flux à donner à Meta, paniers reçus comme commandes).
 */
const data = ref(null)
const form = reactive({ enabled: false, when: 'always', from: '19:00', to: '08:00', per_hour: 15 })
const busy = ref(false)
const saved = ref(false)
const error = ref('')
const errors = ref({})
const copied = ref(false)

function apply(d) {
  data.value = d
  Object.assign(form, d.auto_replies)
}

async function load() {
  try {
    apply((await http.get('/whatsapp/sales')).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  }
}

async function save() {
  if (form.enabled && !data.value.auto_replies.enabled && data.value.channel === 'linked'
    && !window.confirm('Les réponses partiront de votre WhatsApp relié par QR code, une connexion non officielle. WhatsApp peut bloquer un numéro qui ressemble à un robot. Les garde-fous limitent ce risque sans le supprimer. Activer quand même ?')) {
    form.enabled = false
    return
  }
  busy.value = true
  error.value = ''
  errors.value = {}
  saved.value = false
  try {
    apply((await http.put('/whatsapp/sales', form)).data.data)
    saved.value = true
  } catch (e) {
    errors.value = fieldErrors(e)
    error.value = Object.keys(errors.value).length ? '' : errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function copy() {
  try {
    await navigator.clipboard.writeText(data.value.catalog.feed_url)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    window.prompt('Copiez ce lien :', data.value.catalog.feed_url)
  }
}

onMounted(load)
</script>

<template>
  <div v-if="data" class="space-y-4">
    <!-- Réponses automatiques -->
    <section class="card space-y-3 p-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="font-semibold">Réponses automatiques</h3>
        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="data.auto_replies.enabled ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700'">
          {{ data.auto_replies.enabled ? 'Activées' : 'Désactivées' }}
        </span>
      </div>
      <p class="text-sm text-slate-600">
        Quand un client demande si un article est disponible, son prix, les tailles ou les frais de livraison, l'assistant
        cherche dans votre catalogue et votre stock, puis répond à votre place. Il ne répond pas s'il a un doute
        (réclamation, négociation, information manquante) : vous gardez la main et recevez la notification habituelle.
      </p>
      <ul class="list-disc space-y-1 pl-5 text-xs text-slate-500">
        <li>Réponse environ 45 secondes après le dernier message du client, avec « en train d'écrire ».</li>
        <li>Pas de réponse automatique pendant 30 minutes après une réponse de votre équipe.</li>
        <li>Au plus 6 réponses automatiques par client et par jour, et le plafond par heure ci-dessous.</li>
        <li>Chaque réponse compte une action de l'assistant IA de votre offre.</li>
      </ul>

      <Alert v-if="data.channel === 'linked'" tone="warning">
        Les réponses partiront de votre WhatsApp relié par QR code. Cette connexion n'est pas officielle : WhatsApp peut
        bloquer un numéro qui se comporte comme un robot. Gardez un plafond bas, ou utilisez la connexion officielle de Meta,
        faite pour l'automatisation.
      </Alert>
      <Alert v-else-if="data.channel === null" tone="warning">Reliez d'abord votre WhatsApp (plus haut) ou connectez la connexion officielle de Meta.</Alert>
      <Alert v-if="!data.assistant.enabled" tone="warning">L'assistant IA n'est pas encore activé sur la plateforme.</Alert>
      <p v-else-if="data.assistant.limit === 0" class="text-xs text-slate-500">
        Assistant IA : {{ data.assistant.credits }} actions en réserve. Rechargez dans Réglages › Abonnement.
      </p>
      <p v-else-if="data.assistant.limit !== null" class="text-xs text-slate-500">
        Assistant IA ce mois-ci : {{ data.assistant.used }} / {{ data.assistant.limit }} actions<template v-if="data.assistant.credits"> (+ {{ data.assistant.credits }} en réserve)</template>.
      </p>

      <form class="space-y-3" @submit.prevent="save">
        <label class="flex items-center gap-2 text-sm font-medium">
          <input v-model="form.enabled" type="checkbox" class="h-4 w-4 accent-emerald-700" />
          Répondre automatiquement aux questions simples
        </label>
        <fieldset class="space-y-2 text-sm" :disabled="!form.enabled">
          <label class="flex items-center gap-2"><input v-model="form.when" type="radio" value="always" class="accent-emerald-700" /> À toute heure</label>
          <label class="flex flex-wrap items-center gap-2">
            <input v-model="form.when" type="radio" value="away" class="accent-emerald-700" /> Seulement de
            <input v-model="form.from" type="time" class="input w-32 py-1" aria-label="Heure de début" />
            à
            <input v-model="form.to" type="time" class="input w-32 py-1" aria-label="Heure de fin" />
            <span class="text-xs text-slate-500">(par exemple la nuit, quand personne ne répond)</span>
          </label>
          <p v-if="errors.to || errors.from" class="text-xs text-red-600">{{ errors.to ?? errors.from }}</p>
          <label class="flex flex-wrap items-center gap-2">
            Au plus
            <input v-model.number="form.per_hour" type="number" min="1" max="60" class="input w-20 py-1" aria-label="Réponses par heure" />
            réponses automatiques par heure
          </label>
          <p v-if="errors.per_hour" class="text-xs text-red-600">{{ errors.per_hour }}</p>
        </fieldset>
        <div class="flex flex-wrap items-center gap-3">
          <button class="btn-brand" :disabled="busy">{{ busy ? 'Enregistrement…' : 'Enregistrer' }}</button>
          <span v-if="saved" class="text-sm text-emerald-700">Enregistré.</span>
          <span class="text-xs text-slate-500">{{ data.sent_last_7_days }} réponse(s) automatique(s) ces 7 derniers jours.</span>
        </div>
      </form>
    </section>

    <!-- Catalogue WhatsApp -->
    <section class="card space-y-3 p-4">
      <h3 class="font-semibold">Catalogue WhatsApp</h3>
      <p class="text-sm text-slate-600">
        Vos clients voient vos produits dans WhatsApp, remplissent un panier et vous l'envoient. Le panier arrive dans
        Messages et devient une commande « À confirmer » : il ne reste qu'à demander le quartier et l'adresse.
      </p>
      <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-700">
        <li>Ouvrez le Gestionnaire de catalogue de Meta (business.facebook.com › Commerce) et créez un catalogue « E-commerce ».</li>
        <li>Choisissez « Ajouter des articles » › « Flux de données » › « Utiliser une URL », collez le lien ci-dessous et demandez une mise à jour chaque heure.</li>
        <li>Reliez ce catalogue à votre compte WhatsApp : dans le Gestionnaire WhatsApp (connexion officielle), ou dans l'application WhatsApp Business › Outils professionnels › Catalogue.</li>
      </ol>
      <div class="flex flex-wrap items-center gap-2">
        <input :value="data.catalog.feed_url" readonly class="input min-w-0 flex-1 font-mono text-xs" aria-label="Lien du catalogue" @focus="$event.target.select()" />
        <button type="button" class="btn-ghost" @click="copy">{{ copied ? 'Copié ✓' : 'Copier le lien' }}</button>
      </div>
      <p class="text-xs text-slate-500">
        Prix, stock et photos se mettent à jour tout seuls à chaque lecture par Meta.
        {{ data.catalog.orders_last_30_days }} commande(s) reçue(s) par le catalogue ces 30 derniers jours.
        Avec la connexion officielle, chaque panier arrive complet ; avec le WhatsApp relié, WhatsApp transmet parfois
        moins de détails : vérifiez alors les articles avant de confirmer.
      </p>
    </section>
    <Alert v-if="error">{{ error }}</Alert>
  </div>
  <Alert v-else-if="error">{{ error }}</Alert>
</template>
