<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../Alert.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'

// Messages WhatsApp d'accueil des nouveaux commerçants, envoyés par le numéro du support
const data = ref(null)
const form = ref(null)
const open = ref(false)
const busy = ref(false)
const error = ref('')
const errors = ref({})
const saved = ref(false)

const STEPS = [
  { key: 'welcome', label: 'Bienvenue', when: '1 minute après la création de la boutique' },
  { key: 'welcome_premium', label: 'Bienvenue · carte Membre Premium', when: 'à la place du message de bienvenue', stat: 'welcome' },
  { key: 'welcome_partner', label: 'Bienvenue · invité par une entreprise de livraison', when: 'à la place du message de bienvenue', stat: 'welcome' },
  { key: 'no_product', label: 'Premier produit', when: '3 h après, s\'il n\'a ajouté aucun produit' },
  { key: 'first_products', label: 'Vente en live', when: '20 h après, s\'il a des produits' },
  { key: 'trial_end', label: 'Fin de l\'essai', when: '3 jours avant la fin de l\'essai' },
]
const VARIABLES = '{prenom} {boutique} {lien_boutique} {lien_produit} {lien_live} {lien_abonnement} {lien_video} {nb_produits} {essai} {offre_essai} {date_fin} {prix} {numero_carte} {offre} {entreprise}'

function fill(d) {
  data.value = d
  form.value = { enabled: d.enabled, texts: { ...d.texts }, trial_template: d.trial_template ?? '' }
}

onMounted(async () => {
  try {
    fill((await http.get('/console/whatsapp/onboarding')).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  }
})

async function save() {
  busy.value = true
  error.value = ''
  errors.value = {}
  saved.value = false
  try {
    fill((await http.put('/console/whatsapp/onboarding', form.value)).data.data)
    saved.value = true
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

function reset(key) {
  form.value.texts[key] = data.value.defaults[key]
}
</script>

<template>
  <section v-if="form" class="card max-w-3xl space-y-3 p-4 text-sm">
    <div class="flex flex-wrap items-center gap-3">
      <h3 class="flex-1 text-base font-semibold">Messages d'accueil des nouveaux commerçants</h3>
      <label class="flex items-center gap-2">
        <input v-model="form.enabled" type="checkbox" class="h-4 w-4 accent-emerald-700" @change="save" />
        <span>{{ form.enabled ? 'Activés' : 'Arrêtés' }}</span>
      </label>
    </div>
    <p class="text-slate-600">
      Envoyés par le WhatsApp du support : au plus 3 messages dans les 24 h qui suivent l'inscription, puis le rappel de fin d'essai.
      Chaque relance s'annule si le commerçant a déjà fait l'étape. Rien entre 21 h et 7 h. « STOP » arrête tout pour lui, « START » reprend.
    </p>
    <ul class="grid gap-1 text-slate-700 sm:grid-cols-2">
      <li v-for="s in STEPS.filter((s) => !s.stat)" :key="s.key">{{ s.label }} : <strong>{{ data.stats[s.key]?.sent ?? 0 }}</strong> envoyés sur 30 jours<template v-if="data.stats[s.key]?.failed"> · {{ data.stats[s.key].failed }} en échec</template></li>
    </ul>
    <Alert v-if="error">{{ error }}</Alert>
    <button v-if="!open" type="button" class="text-emerald-700 underline" @click="open = true">Voir et modifier les textes</button>
    <form v-else class="space-y-4" @submit.prevent="save">
      <p class="text-xs text-slate-500">Variables remplacées automatiquement : <code>{{ VARIABLES }}</code>. *texte* s'affiche en gras dans WhatsApp.</p>
      <div v-for="s in STEPS" :key="s.key" class="space-y-1">
        <div class="flex items-baseline gap-2">
          <label :for="`ob-${s.key}`" class="font-semibold">{{ s.label }}</label>
          <span class="text-xs text-slate-500">{{ s.when }}</span>
          <button v-if="form.texts[s.key] !== data.defaults[s.key]" type="button" class="ml-auto text-xs text-slate-500 underline" @click="reset(s.key)">Texte proposé</button>
        </div>
        <textarea :id="`ob-${s.key}`" v-model="form.texts[s.key]" rows="6" class="input font-mono text-xs" maxlength="1000" />
      </div>
      <label class="block">Modèle Meta pour la fin de l'essai <span class="text-slate-500">(connexion officielle seulement, hors des 24 h)</span>
        <input v-model="form.trial_template" class="input mt-1 max-w-xs font-mono" placeholder="fin_essai_jibiat" />
        <span class="block text-xs text-slate-500">Modèle validé par Meta, en français, avec 5 variables dans cet ordre : prénom, boutique, date de fin, prix, lien. Sans modèle, ce rappel ne part que par QR code.</span>
        <span v-if="errors.trial_template" class="text-red-700">{{ errors.trial_template }}</span>
      </label>
      <div class="flex items-center gap-3">
        <button class="btn-brand" :disabled="busy">{{ busy ? 'Enregistrement…' : 'Enregistrer' }}</button>
        <span v-if="saved" class="text-emerald-700">Enregistré.</span>
      </div>
    </form>
  </section>
</template>
