<script setup>
import { onMounted, reactive, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { formatDateTime } from '../../../lib/format'
import { connectWhatsApp } from '../../../lib/facebook'

const data = ref(null)
const error = ref('')
const saved = ref(false)
const busy = ref(false)
const formErrors = ref({})
const form = reactive({ phone_number_id: '', waba_id: '', display_phone: '', access_token: '', app_secret: '', templates: {}, automations: {}, status: 'active' })

const STATUS = { queued: 'En file', sent: 'Envoyé', delivered: 'Reçu', read: 'Lu', failed: 'Échec' }
const STATUS_TONES = { failed: 'bg-red-100 text-red-800', read: 'bg-emerald-100 text-emerald-800', delivered: 'bg-sky-100 text-sky-800' }

function fill() {
  const a = data.value.account
  Object.assign(form, {
    phone_number_id: a?.phone_number_id ?? '',
    waba_id: a?.waba_id ?? '',
    display_phone: a?.display_phone ?? '',
    access_token: '',
    app_secret: '',
    status: a?.status === 'disabled' ? 'disabled' : 'active',
    templates: Object.fromEntries(Object.keys(data.value.events).map((k) => [k, a?.templates?.[k] ?? ''])),
    automations: Object.fromEntries(Object.keys(data.value.events).map((k) => [k, !!a?.automations?.[k]])),
  })
}

async function load() {
  try {
    data.value = (await http.get('/whatsapp')).data.data
    fill()
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function save() {
  busy.value = true
  error.value = ''
  saved.value = false
  formErrors.value = {}
  try {
    const payload = { ...form, templates: Object.fromEntries(Object.entries(form.templates).map(([k, v]) => [k, v || null])) }
    data.value = (await http.put('/whatsapp', payload)).data.data
    fill()
    saved.value = true
  } catch (e) {
    formErrors.value = fieldErrors(e)
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function disconnect() {
  if (!window.confirm('Déconnecter le WhatsApp de la boutique ? Les messages automatiques s\'arrêtent.')) return
  data.value = (await http.delete('/whatsapp')).data.data
  fill()
}

const copy = (text) => navigator.clipboard?.writeText(text)

// Connexion en un clic : fenêtre Facebook (inscription intégrée), puis le serveur fait le reste
const coexistence = ref(false)
const connecting = ref(false)
const warnings = ref([])
async function connectFacebook() {
  connecting.value = true
  error.value = ''
  saved.value = false
  warnings.value = []
  try {
    const result = await connectWhatsApp({ appId: data.value.embedded.app_id, configId: data.value.embedded.config_id, version: data.value.embedded.graph_version, coexistence: coexistence.value })
    const response = (await http.post('/whatsapp/embedded', { ...result, coexistence: coexistence.value })).data.data
    warnings.value = response.warnings ?? []
    data.value = response
    fill()
    saved.value = true
  } catch (e) {
    error.value = e.response ? errorMessage(e) : e.message
  } finally {
    connecting.value = false
  }
}
const TEMPLATE_STATUS = { APPROVED: ['Approuvé', 'bg-emerald-100 text-emerald-800'], PENDING: ['En vérification chez Meta', 'bg-amber-100 text-amber-900'], REJECTED: ['Refusé', 'bg-red-100 text-red-800'], ERROR: ['Non créé', 'bg-red-100 text-red-800'], PAUSED: ['En pause', 'bg-slate-200 text-slate-700'], DISABLED: ['Désactivé', 'bg-slate-200 text-slate-700'] }
const embeddedAccount = () => data.value.account?.connection === 'embedded'
const manual = ref(false) // connexion manuelle choisie alors que le bouton est proposé

// Création de l'accès WhatsApp Business chez Meta, étape par étape : lien direct et clics à faire sur chaque écran
const META_STEPS = [
  {
    title: 'Créer votre portefeuille Meta Business',
    url: 'https://business.facebook.com/overview', link: 'Ouvrir Meta Business',
    items: ['Connectez-vous avec le compte Facebook du gérant.', 'Créez le portefeuille : nom de la boutique, votre nom, e-mail professionnel.'],
  },
  {
    title: 'Vous inscrire comme développeur Meta (une seule fois)',
    url: 'https://developers.facebook.com/', link: 'Ouvrir Meta for Developers',
    items: [
      'En haut à droite, cliquez sur « Commencer » (ou « Get Started »).',
      'Acceptez les conditions, confirmez votre numéro ou votre e-mail, choisissez « Développeur » comme rôle.',
      'Si vous voyez « Mes applications » en haut à droite, c\'est déjà fait : passez à l\'étape suivante.',
    ],
  },
  {
    title: 'Créer l\'application WhatsApp',
    url: 'https://developers.facebook.com/apps/', link: 'Ouvrir Mes applications',
    items: [
      'Cliquez sur « Créer une application » (« Create App »).',
      'Nom de l\'application (ex. « Awa Mode WhatsApp ») et votre e-mail, puis « Suivant ».',
      'Cas d\'utilisation : « Contacter les clients via WhatsApp » (« Connect with customers through WhatsApp »), puis « Suivant ».',
      'Choisissez le portefeuille créé à l\'étape 1, puis « Créer l\'application ».',
    ],
  },
  {
    title: 'Ajouter le numéro de la boutique et copier ses identifiants',
    url: 'https://developers.facebook.com/apps/', link: 'Ouvrir Mes applications',
    items: [
      'Ouvrez l\'application, puis WhatsApp › Configuration de l\'API (« API Setup »).',
      'En bas de la page : « Ajouter un numéro de téléphone », nom affiché de la boutique, puis le code reçu par SMS ou appel.',
      'Choisissez ce numéro dans la liste « De » (« From ») : copiez « Phone number ID » et « WhatsApp Business Account ID » dans le formulaire ci-dessous.',
    ],
  },
  {
    title: 'Créer le jeton d\'accès permanent',
    url: 'https://business.facebook.com/settings/system-users', link: 'Ouvrir Utilisateurs système',
    items: [
      '« Ajouter » : un nom (ex. « jibiat »), rôle « Admin ».',
      '« Attribuer des éléments » : Applications › votre application › Contrôle total ; Comptes WhatsApp › votre compte › Contrôle total.',
      '« Générer un jeton » : votre application, expiration « Jamais », droits whatsapp_business_messaging et whatsapp_business_management.',
      'Copiez le jeton tout de suite (Meta ne l\'affiche qu\'une fois) dans « Jeton d\'accès permanent » ci-dessous.',
    ],
  },
  {
    title: 'Copier la clé secrète de l\'application',
    url: 'https://developers.facebook.com/apps/', link: 'Ouvrir Mes applications',
    items: ['Ouvrez l\'application, puis Paramètres de l\'application › Général (« App settings › Basic »).', 'Clé secrète (« App secret ») › « Afficher » : copiez-la ci-dessous.'],
  },
  {
    title: 'Faire approuver vos modèles de messages',
    url: 'https://business.facebook.com/wa/manage/message-templates/', link: 'Ouvrir les modèles de messages',
    items: ['Un modèle par message automatique, catégorie « Utilitaire », langue « Français ».', 'Variables dans l\'ordre indiqué plus bas ; Meta répond en général en quelques minutes.'],
  },
  {
    title: 'Ajouter un moyen de paiement chez Meta',
    url: 'https://business.facebook.com/billing_hub/payment_settings', link: 'Ouvrir les paiements Meta',
    items: ['Meta facture les messages envoyés à l\'initiative de la boutique (confirmations, suivi…). Les réponses aux clients dans les 24 h sont gratuites.'],
  },
]
</script>

<template>
  <div v-if="data" class="space-y-5">
    <p class="text-sm text-slate-600">
      Envoyez automatiquement les messages à vos clients depuis le numéro WhatsApp Business de la boutique (API Cloud de Meta).
      Chaque message utilise un modèle que vous avez fait approuver chez Meta ; indiquez ici son nom.
    </p>
    <section v-if="data.embedded && (!data.account || embeddedAccount())" class="card space-y-3 border-emerald-200 p-4">
      <template v-if="embeddedAccount()">
        <h3 class="font-semibold">✅ WhatsApp connecté : {{ data.account.display_phone || data.account.phone_number_id }}</h3>
        <p class="text-sm text-slate-600">Connecté avec Facebook. Les modèles de messages ont été créés pour vous et vérifiés par Meta (en général en quelques minutes).</p>
        <button type="button" class="btn-secondary" :disabled="connecting" @click="connectFacebook">{{ connecting ? 'Connexion…' : 'Reconnecter avec Facebook' }}</button>
      </template>
      <template v-else>
        <h3 class="font-semibold">Connecter WhatsApp en un clic</h3>
        <p class="text-sm text-slate-600">
          Une fenêtre Facebook s'ouvre et vous guide : compte Business, nom affiché, numéro de la boutique et code reçu par SMS.
          L'application se branche ensuite toute seule et crée vos modèles de messages. Environ 5 minutes.
        </p>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="coexistence" type="checkbox" class="mt-0.5 h-4 w-4 accent-emerald-700" />
          <span>Mon numéro est déjà dans l'application <strong>WhatsApp Business</strong> du téléphone et je veux continuer à l'utiliser (coexistence, si Meta la propose pour votre numéro).</span>
        </label>
        <button type="button" class="btn-brand" :disabled="connecting" @click="connectFacebook">{{ connecting ? 'Fenêtre Facebook ouverte…' : 'Connecter WhatsApp avec Facebook' }}</button>
      </template>
      <Alert v-for="w in warnings" :key="w" tone="warning">{{ w }}</Alert>
    </section>

    <details v-if="!embeddedAccount()" class="card p-4 text-sm" :open="!data.account && !data.embedded" @toggle="manual = $event.target.open">
      <summary class="cursor-pointer font-semibold">{{ data.embedded ? 'Connexion manuelle, sans la fenêtre Facebook (avancé)' : 'Créer votre accès WhatsApp Business chez Meta (environ 30 minutes)' }}</summary>
      <p class="mt-2 text-slate-600">
        Gratuit à créer. Le numéro utilisé ne doit plus être actif dans l'application WhatsApp du téléphone, sauf si Meta vous propose de garder les deux (« coexistence »).
        <a href="https://developers.facebook.com/documentation/business-messaging/whatsapp/get-started" target="_blank" rel="noopener" class="text-emerald-700 underline">Guide de Meta</a>
      </p>
      <ol class="mt-3 space-y-3">
        <li v-for="(step, i) in META_STEPS" :key="step.title" class="flex gap-3">
          <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-emerald-100 text-xs font-semibold text-emerald-800">{{ i + 1 }}</span>
          <div class="min-w-0">
            <p class="font-medium">{{ step.title }}</p>
            <a :href="step.url" target="_blank" rel="noopener" class="font-medium text-emerald-700 hover:underline">{{ step.link }} ↗</a>
            <ul class="mt-1 list-disc space-y-0.5 pl-5 text-slate-600">
              <li v-for="item in step.items" :key="item">{{ item }}</li>
            </ul>
          </div>
        </li>
        <li class="flex gap-3">
          <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-emerald-100 text-xs font-semibold text-emerald-800">{{ META_STEPS.length + 1 }}</span>
          <div class="min-w-0">
            <p class="font-medium">Connecter ici, puis brancher le webhook</p>
            <p class="text-slate-600">Remplissez le formulaire ci-dessous et cliquez sur « Connecter WhatsApp ». L'URL de rappel et le jeton de vérification s'affichent alors : collez-les dans l'application Meta (WhatsApp › Configuration › Webhook) et abonnez le champ « messages ».</p>
          </div>
        </li>
      </ol>
    </details>
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="saved" tone="success">Réglages enregistrés.</Alert>
    <Alert v-if="data.account?.last_error" tone="warning">Dernière erreur de Meta : {{ data.account.last_error }}</Alert>

    <form v-if="data.account || !data.embedded || manual" class="space-y-5" @submit.prevent="save">
      <section v-if="!embeddedAccount()" class="card space-y-4 p-4">
        <h3 class="font-semibold">Numéro WhatsApp Business</h3>
        <div class="grid gap-4 sm:grid-cols-2">
          <Field id="wa-phone-id" label="Identifiant du numéro (Phone number ID)" :error="formErrors.phone_number_id">
            <input id="wa-phone-id" v-model="form.phone_number_id" class="input" maxlength="40" required />
          </Field>
          <Field id="wa-display" label="Numéro affiché" :error="formErrors.display_phone">
            <input id="wa-display" v-model="form.display_phone" class="input" maxlength="20" placeholder="+225 07 00 00 00 00" />
          </Field>
          <Field id="wa-waba" label="Identifiant du compte WhatsApp Business (WABA ID)" :error="formErrors.waba_id">
            <input id="wa-waba" v-model="form.waba_id" class="input" maxlength="40" />
          </Field>
          <Field id="wa-status" label="État">
            <select id="wa-status" v-model="form.status" class="input"><option value="active">Actif</option><option value="disabled">En pause</option></select>
          </Field>
          <Field id="wa-token" label="Jeton d'accès permanent" :error="formErrors.access_token" :hint="data.account ? 'Laisser vide pour garder le jeton enregistré.' : ''">
            <input id="wa-token" v-model="form.access_token" class="input" type="password" autocomplete="off" :required="!data.account" />
          </Field>
          <Field id="wa-secret" label="Clé secrète de l'application Meta" :error="formErrors.app_secret" :hint="data.account ? 'Laisser vide pour garder la clé enregistrée.' : 'Sert à vérifier que les notifications viennent bien de Meta.'">
            <input id="wa-secret" v-model="form.app_secret" class="input" type="password" autocomplete="off" :required="!data.account" />
          </Field>
        </div>
        <div v-if="data.account" class="space-y-2 rounded-lg bg-slate-50 p-3 text-sm">
          <p class="text-slate-600">À renseigner dans votre application Meta (WhatsApp › Configuration › Webhook, champ « messages ») :</p>
          <div class="flex items-center gap-2"><span class="w-32 shrink-0 text-slate-500">URL de rappel</span><code class="min-w-0 flex-1 truncate">{{ data.account.webhook_url }}</code><button type="button" class="btn-ghost px-2 py-1" @click="copy(data.account.webhook_url)">Copier</button></div>
          <div class="flex items-center gap-2"><span class="w-32 shrink-0 text-slate-500">Jeton de vérification</span><code class="min-w-0 flex-1 truncate">{{ data.account.verify_token }}</code><button type="button" class="btn-ghost px-2 py-1" @click="copy(data.account.verify_token)">Copier</button></div>
        </div>
      </section>

      <section class="card p-4">
        <h3 class="mb-1 font-semibold">Messages automatiques</h3>
        <p class="mb-3 text-sm text-slate-500">Les variables du modèle doivent être dans l'ordre indiqué ({{ '{' + '{1}' + '}' }}, {{ '{' + '{2}' + '}' }}…).</p>
        <ul class="divide-y divide-slate-100">
          <li v-for="(event, key) in data.events" :key="key" class="grid gap-3 py-3 sm:grid-cols-[1fr_16rem] sm:items-start">
            <div class="text-sm">
              <label class="flex items-center gap-2 font-medium">
                <input v-model="form.automations[key]" type="checkbox" class="h-4 w-4 accent-emerald-700" />
                {{ event.label }}
              </label>
              <p class="mt-1 text-xs text-slate-500">
                Variables : <span v-for="(v, i) in event.variables" :key="v">{{ '{' + '{' + (i + 1) + '}' + '}' }} {{ v }}<template v-if="i < event.variables.length - 1">, </template></span>
                <template v-if="event.buttons.length"> · Boutons de réponse rapide : {{ event.buttons.join(', ') }}</template>
              </p>
            </div>
            <Field :id="`tpl-${key}`" label="Nom du modèle Meta" :error="formErrors[`templates.${key}`]">
              <input :id="`tpl-${key}`" v-model="form.templates[key]" class="input" maxlength="100" placeholder="ex. confirmation_commande" />
              <span v-if="TEMPLATE_STATUS[data.account?.template_status?.[key]]" class="mt-1 inline-block rounded px-1.5 py-0.5 text-xs" :class="TEMPLATE_STATUS[data.account.template_status[key]][1]">{{ TEMPLATE_STATUS[data.account.template_status[key]][0] }}</span>
            </Field>
          </li>
        </ul>
      </section>

      <div class="flex flex-wrap gap-2">
        <button class="btn-brand" :disabled="busy">{{ data.account ? 'Enregistrer' : 'Connecter WhatsApp' }}</button>
        <button v-if="data.account" type="button" class="btn-ghost text-red-600" @click="disconnect">Déconnecter</button>
      </div>
    </form>

    <section class="card p-4 text-sm">
      <h3 class="mb-2 font-semibold">Derniers messages envoyés</h3>
      <ul class="divide-y divide-slate-100">
        <li v-for="m in data.messages" :key="m.id" class="flex flex-wrap items-center gap-3 py-2">
          <span class="flex-1">{{ data.events[m.event]?.label ?? m.event }} → {{ m.to }}<span class="block text-xs text-slate-500">{{ formatDateTime(m.created_at) }}<template v-if="m.error"> · {{ m.error }}</template></span></span>
          <RouterLink v-if="m.related_type === 'order'" :to="`/gestion/commandes/${m.related_id}`" class="text-xs text-emerald-700 hover:underline">Commande</RouterLink>
          <span class="rounded px-2 py-0.5 text-xs" :class="STATUS_TONES[m.status] ?? 'bg-slate-100 text-slate-700'">{{ STATUS[m.status] ?? m.status }}</span>
        </li>
        <li v-if="!data.messages.length" class="py-4 text-slate-500">Aucun message pour l'instant.</li>
      </ul>
    </section>
  </div>
  <p v-else-if="!error" class="text-sm text-slate-500">Chargement…</p>
  <Alert v-else>{{ error }}</Alert>
</template>
