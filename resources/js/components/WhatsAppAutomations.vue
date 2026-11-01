<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Alert from './Alert.vue'
import ColorIcon from './ColorIcon.vue'
import Field from './Field.vue'
import Icon from './Icon.vue'
import Modal from './Modal.vue'
import ToggleSwitch from './ToggleSwitch.vue'
import http, { errorMessage, fieldErrors } from '../lib/http'
import { boot } from '../lib/boot'
import { facebookRelayUrl, takeFacebookResult } from '../lib/facebook'

/**
 * Messages automatiques (numéro officiel, API Cloud de Meta) : connexion en un clic avec Facebook (ou avancée, avec
 * les identifiants Meta), puis un interrupteur par message, l'état du modèle chez Meta et l'aperçu du message reçu.
 */
const props = defineProps({ data: { type: Object, required: true } })
const emit = defineEmits(['update'])

const error = ref('')
const notice = ref('')
const busy = ref('')
const open = ref('') // message dont l'aperçu est ouvert
const editing = reactive({}) // nom de modèle en cours de modification, par message

const account = computed(() => props.data.account)
const embedded = computed(() => account.value?.connection === 'embedded')

// Nom affiché du numéro, vérifié par Meta
const NAME = {
  APPROVED: ['Nom approuvé', 'text-emerald-700'],
  AVAILABLE_WITHOUT_REVIEW: ['Nom approuvé', 'text-emerald-700'],
  PENDING_REVIEW: ['Nom en vérification chez Meta', 'text-amber-700'],
  DECLINED: ['Nom refusé par Meta', 'text-red-700'],
  EXPIRED: ['Nom expiré', 'text-red-700'],
}
const nameState = computed(() => NAME[account.value?.name_status] ?? null)

// Messages par destinataire, avec un nom court et le moment de l'envoi
const MESSAGES = {
  order_confirmation: ['Demande de confirmation', 'Dès qu\'une commande est à confirmer'],
  courier_on_the_way: ['Livreur en route', 'Quand votre livreur part livrer'],
  order_shipped: ['Commande en route', 'Quand la commande est expédiée'],
  order_expedited: ['Colis expédié', 'Au dépôt à la gare, pour les autres villes'],
  order_delivered: ['Commande livrée', 'Quand la commande est livrée'],
  review_request: ['Demande d\'avis', '24 h après la livraison'],
  payment_link: ['Lien de paiement', 'Quand vous envoyez un lien de paiement'],
  cart_recovery: ['Panier abandonné', 'Quand un client laisse son panier sur la boutique en ligne'],
  campaign: ['Relance des clients (nouveautés, offres)', 'Envoyée depuis Marketing › Relances WhatsApp'],
  daily_report: ['Rapport du jour', 'Chaque soir, à l\'équipe'],
  daily_tips: ['Conseils du soir', 'Après le rapport, à l\'équipe'],
}
const TEAM = ['daily_report', 'daily_tips']
const groups = computed(() => {
  const keys = Object.keys(props.data.events)
  return [
    { title: 'Pour vos clients', keys: keys.filter((k) => !TEAM.includes(k)) },
    { title: 'Pour votre équipe', keys: keys.filter((k) => TEAM.includes(k)) },
  ]
})
const label = (key) => MESSAGES[key]?.[0] ?? props.data.events[key].label
const when = (key) => MESSAGES[key]?.[1] ?? ''

const TEMPLATE_STATUS = {
  APPROVED: ['Approuvé par Meta', 'bg-emerald-100 text-emerald-800'],
  PENDING: ['En attente chez Meta', 'bg-amber-100 text-amber-900'],
  REJECTED: ['Refusé par Meta', 'bg-red-100 text-red-800'],
  ERROR: ['Modèle non créé', 'bg-red-100 text-red-800'],
  PAUSED: ['En pause chez Meta', 'bg-slate-200 text-slate-700'],
  DISABLED: ['Désactivé par Meta', 'bg-slate-200 text-slate-700'],
}
const templateName = (key) => account.value?.templates?.[key] ?? ''
function badge(key) {
  if (!templateName(key)) return ['Modèle à indiquer', 'bg-amber-100 text-amber-900']
  return TEMPLATE_STATUS[account.value?.template_status?.[key]] ?? ['Modèle indiqué', 'bg-slate-100 text-slate-700']
}
const isOn = (key) => !!account.value?.automations?.[key]

async function put(payload, key) {
  busy.value = key
  error.value = ''
  try {
    emit('update', (await http.put('/whatsapp', payload)).data.data)
    return true
  } catch (e) {
    error.value = fieldErrors(e)[`templates.${key}`] ?? errorMessage(e)
    return false
  } finally {
    busy.value = ''
  }
}

async function toggle(key, on) {
  // Sans nom de modèle, le message ne peut pas partir : on demande d'abord le modèle
  if (on && !templateName(key)) {
    open.value = key
    editing[key] = ''
    return
  }
  await put({ automations: { [key]: on } }, key)
}

async function saveTemplate(key) {
  const name = (editing[key] ?? '').trim()
  if (await put({ templates: { [key]: name || null }, ...(name ? { automations: { [key]: true } } : {}) }, key)) delete editing[key]
}

// Connexion en un clic : la fenêtre Facebook s'ouvre depuis la plateforme (seul domaine déclaré chez Meta).
// Numéro déjà dans l'application WhatsApp Business : Meta le détecte et propose de l'y garder (coexistence).
const connecting = ref(false)
const warnings = ref([])
function connectFacebook() {
  connecting.value = true
  const { app_id: appId, config_id: configId, graph_version: version } = props.data.embedded
  window.location.assign(facebookRelayUrl(boot.platformUrl, { appId, configId, version, coexistence: 'auto' }, window.location.origin + window.location.pathname))
}
async function finishFacebook(result) {
  connecting.value = true
  error.value = ''
  warnings.value = []
  try {
    const response = (await http.post('/whatsapp/embedded', result)).data.data
    warnings.value = response.warnings ?? []
    emit('update', response)
    notice.value = 'WhatsApp connecté ✓ Vos modèles de messages ont été créés et partent en vérification chez Meta.'
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    connecting.value = false
  }
}
onMounted(() => {
  const facebook = takeFacebookResult()
  if (facebook?.error) error.value = facebook.error
  else if (facebook) finishFacebook(facebook)
})

// Connexion avancée : identifiants Meta saisis à la main (fenêtre)
const advanced = ref(false)
const form = reactive({ phone_number_id: '', waba_id: '', display_phone: '', access_token: '', app_secret: '' })
const formErrors = ref({})
const formBusy = ref(false)
function openAdvanced() {
  const a = account.value
  Object.assign(form, { phone_number_id: a?.phone_number_id ?? '', waba_id: a?.waba_id ?? '', display_phone: a?.display_phone ?? '', access_token: '', app_secret: '' })
  formErrors.value = {}
  advanced.value = true
  menu.value = false
}
async function saveAdvanced() {
  formBusy.value = true
  formErrors.value = {}
  const creating = !account.value
  try {
    emit('update', (await http.put('/whatsapp', { ...form })).data.data)
    notice.value = creating ? 'WhatsApp connecté ✓ Collez maintenant l\'URL de rappel et le jeton dans votre application Meta.' : 'Identifiants enregistrés ✓'
    // Première connexion : la fenêtre reste ouverte pour copier l'adresse du webhook
    advanced.value = creating
  } catch (e) {
    formErrors.value = fieldErrors(e)
  } finally {
    formBusy.value = false
  }
}
const copy = (text) => navigator.clipboard?.writeText(text)

// Menu « ⋯ » du numéro officiel
const menu = ref(false)
async function setStatus(status) {
  menu.value = false
  await put({ status }, 'status')
}
async function disconnect() {
  menu.value = false
  if (!window.confirm('Déconnecter votre numéro officiel ? Les messages automatiques s\'arrêtent.')) return
  emit('update', (await http.delete('/whatsapp')).data.data)
}

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
    title: 'Ajouter votre numéro et copier ses identifiants',
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
    items: ['Meta facture les messages envoyés à votre initiative (confirmations, suivi…). Les réponses aux clients dans les 24 h sont gratuites.'],
  },
]
</script>

<template>
  <div class="space-y-4">
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="notice" tone="success">{{ notice }}</Alert>
    <Alert v-for="w in warnings" :key="w" tone="warning">{{ w }}</Alert>

    <!-- Pas encore connecté : une seule carte -->
    <section v-if="!account" class="card space-y-4 p-4 sm:p-5">
      <div class="flex items-start gap-3">
        <ColorIcon name="cloche" :size="44" />
        <div class="min-w-0 flex-1">
          <h3 class="text-[17px] font-extrabold">Envoyez les messages tout seuls</h3>
          <p class="text-sm text-slate-600">Confirmation, livreur en route, livraison, avis, lien de paiement… envoyés automatiquement depuis votre numéro WhatsApp officiel.</p>
        </div>
      </div>
      <template v-if="data.embedded">
        <ul class="grid gap-2 text-sm sm:grid-cols-3">
          <li class="rounded-xl bg-slate-50 p-3"><strong class="block">1. Facebook s'ouvre</strong><span class="text-slate-600">compte Business et numéro</span></li>
          <li class="rounded-xl bg-slate-50 p-3"><strong class="block">2. Code par SMS</strong><span class="text-slate-600">pour vérifier le numéro</span></li>
          <li class="rounded-xl bg-slate-50 p-3"><strong class="block">3. C'est prêt</strong><span class="text-slate-600">modèles de messages créés pour vous</span></li>
        </ul>
        <!-- Bonnes pratiques : quel numéro, quel compte (Meta bloque souvent les comptes neufs ou incohérents) -->
        <details class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950" open>
          <summary class="cursor-pointer font-bold">Avant de commencer : quel numéro et quel compte ?</summary>
          <div class="mt-2 space-y-3">
            <div>
              <p class="font-semibold">Le numéro</p>
              <ul class="ml-4 list-disc space-y-1">
                <li>Un numéro <strong>de la boutique</strong>, ivoirien (+225), que vous garderez longtemps. L'idéal : une puce réservée à la boutique.</li>
                <li>Il doit pouvoir recevoir un <strong>SMS ou un appel</strong> pour le code de vérification.</li>
                <li><strong>Déjà dans l'application WhatsApp Business</strong> (mise à jour) : Meta le reconnaît et vous propose de le garder sur le téléphone. Vous répondez alors depuis le téléphone et depuis Jibiat Marchand.</li>
                <li><strong>Dans WhatsApp « normal »</strong> (pas Business) : Meta le refuse. Passez d'abord ce numéro sur l'application WhatsApp Business (vos discussions sont gardées), utilisez-la quelques jours, puis revenez. Sinon, prenez un numéro sans WhatsApp.</li>
                <li>Ne choisissez pas le <strong>numéro américain</strong> que Meta propose parfois : vos clients verraient un numéro étranger.</li>
              </ul>
            </div>
            <div>
              <p class="font-semibold">Le compte</p>
              <ul class="ml-4 list-disc space-y-1">
                <li>Le <strong>vrai compte Facebook du gérant</strong>, ancien et à son nom, avec la double authentification. Pas un compte créé pour l'occasion : Meta bloque souvent les comptes neufs.</li>
                <li>Un compte Business (portefeuille Meta) au <strong>nom de la boutique</strong>, avec un e-mail qui fonctionne. Une page Facebook de la boutique aide beaucoup.</li>
                <li>Nom affiché : <strong>le nom de la boutique</strong>, écrit comme sur sa page Facebook. Évitez les noms vagues, le mot « WhatsApp » et les majuscules partout.</li>
                <li>Ajoutez une <strong>carte de paiement</strong> chez Meta : sans elle, les messages automatiques (confirmation, livraison…) ne partent pas.</li>
                <li>Si Meta demande une vérification, faites-la sans recommencer la connexion plusieurs fois de suite.</li>
              </ul>
            </div>
          </div>
        </details>
        <div class="flex flex-wrap items-center gap-3">
          <button type="button" class="btn-brand" :disabled="connecting" @click="connectFacebook">{{ connecting ? 'Connexion…' : 'Connecter en un clic avec Facebook' }}</button>
          <span class="text-sm text-slate-500">Environ 5 minutes</span>
        </div>
        <p class="border-t border-slate-100 pt-3 text-xs text-slate-500">
          Vous avez déjà vos identifiants Meta ?
          <button type="button" class="font-medium text-emerald-700 underline" @click="openAdvanced">Connexion avancée</button>
        </p>
      </template>
      <div v-else class="flex flex-wrap items-center gap-3">
        <button type="button" class="btn-brand" @click="openAdvanced">Connecter avec vos identifiants Meta</button>
        <span class="text-sm text-slate-500">Guide pas à pas, environ 30 minutes</span>
      </div>
    </section>

    <template v-else>
      <!-- Numéro officiel connecté -->
      <div class="relative flex items-center gap-3 rounded-2xl border border-emerald-200 bg-white p-3 shadow-soft">
        <ColorIcon name="whatsapp" :size="38" />
        <span class="min-w-0 flex-1 text-sm">
          <strong class="block">Numéro officiel {{ account.display_phone || account.phone_number_id }}</strong>
          <span class="block text-xs" :class="account.status === 'disabled' ? 'text-ocre-700' : 'text-slate-500'">
            {{ account.status === 'disabled' ? 'En pause : aucun message ne part' : (embedded ? 'Connecté avec Facebook' : 'Connecté avec vos identifiants Meta') }}
          </span>
          <span v-if="account.verified_name || nameState" class="block text-xs">
            <template v-if="account.verified_name">« {{ account.verified_name }} »</template>
            <span v-if="nameState" :class="nameState[1]"><template v-if="account.verified_name"> · </template>{{ nameState[0] }}</span>
          </span>
        </span>
        <button type="button" class="btn-ghost px-2" aria-label="Plus d'actions" :aria-expanded="menu" @click="menu = !menu"><Icon name="more" :size="18" /></button>
        <div v-if="menu" class="absolute right-2 top-14 z-10 w-60 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 text-sm shadow-lg">
          <button v-if="data.embedded" type="button" class="block w-full px-4 py-2 text-left hover:bg-slate-50" @click="connectFacebook">Reconnecter avec Facebook</button>
          <button type="button" class="block w-full px-4 py-2 text-left hover:bg-slate-50" @click="openAdvanced">{{ embedded ? 'Voir les identifiants' : 'Identifiants et webhook' }}</button>
          <button type="button" class="block w-full px-4 py-2 text-left hover:bg-slate-50" @click="setStatus(account.status === 'disabled' ? 'active' : 'disabled')">{{ account.status === 'disabled' ? 'Reprendre les envois' : 'Mettre les envois en pause' }}</button>
          <button type="button" class="block w-full px-4 py-2 text-left text-red-600 hover:bg-red-50" @click="disconnect">Déconnecter ce numéro</button>
        </div>
      </div>
      <!-- Numéro américain proposé par Meta à la connexion : à remplacer par le numéro de la boutique -->
      <section v-if="account.meta_number" class="card space-y-2 border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        <h3 class="font-semibold">Ce numéro a été fourni par Meta, ce n'est pas le vôtre</h3>
        <p>Vos clients verraient un numéro américain, et aucun message ne part tant que Meta n'a pas approuvé son nom. Connectez plutôt votre propre numéro (+225) : il ne doit pas déjà servir dans l'application WhatsApp ordinaire.</p>
        <button v-if="data.embedded" type="button" class="btn-brand" :disabled="connecting" @click="connectFacebook">{{ connecting ? 'Connexion…' : 'Connecter mon propre numéro' }}</button>
      </section>
      <Alert v-if="account.last_error && account.error_help" tone="warning">
        <strong class="block">{{ account.error_help.title }}</strong>
        <span class="block">{{ account.error_help.help }}</span>
        <span class="mt-1 block text-xs opacity-75">Message de Meta : {{ account.last_error }}</span>
      </Alert>
      <Alert v-else-if="account.last_error" tone="warning">Dernière erreur de Meta : {{ account.last_error }}</Alert>

      <!-- Un interrupteur par message, l'état du modèle et l'aperçu -->
      <section v-for="group in groups" :key="group.title" class="card p-4 sm:p-5">
        <h3 class="font-semibold">{{ group.title }}</h3>
        <ul class="mt-1 divide-y divide-slate-100">
          <li v-for="key in group.keys" :key="key" class="py-3">
            <div class="flex items-center gap-3">
              <button type="button" class="min-w-0 flex-1 text-left" :aria-expanded="open === key" @click="open = open === key ? '' : key">
                <span class="block text-sm font-semibold">{{ label(key) }}</span>
                <span class="block text-xs text-slate-500">{{ when(key) }}</span>
                <span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium" :class="badge(key)[1]">{{ badge(key)[0] }}</span>
              </button>
              <RouterLink v-if="data.events[key].manual" to="/gestion/marketing/relances" class="text-xs font-medium text-emerald-700 hover:underline">Relances</RouterLink>
              <ToggleSwitch v-else :model-value="isOn(key)" :label="label(key)" :disabled="busy === key || account.status === 'disabled'" @update:model-value="toggle(key, $event)" />
            </div>

            <div v-if="open === key" class="mt-3 space-y-2">
              <!-- Aperçu : la bulle telle que le client la reçoit -->
              <div class="rounded-xl bg-[#e9f7ee] p-3">
                <p class="max-w-md whitespace-pre-line rounded-2xl rounded-tl-sm bg-white px-3 py-2 text-sm text-slate-800 shadow-sm">{{ data.previews?.[key] }}</p>
                <div v-if="data.events[key].buttons.length" class="mt-1.5 flex max-w-md gap-1.5">
                  <span v-for="b in data.events[key].buttons" :key="b" class="flex-1 rounded-xl bg-white px-2 py-1.5 text-center text-sm font-medium text-sky-700 shadow-sm">{{ b }}</span>
                </div>
                <p class="mt-2 text-xs text-slate-500">Exemple avec une commande fictive.</p>
              </div>
              <!-- Nom du modèle chez Meta : rempli tout seul avec Facebook, modifiable -->
              <form v-if="key in editing" class="space-y-2" @submit.prevent="saveTemplate(key)">
                <Field :id="`tpl-${key}`" label="Nom du modèle chez Meta">
                  <input :id="`tpl-${key}`" v-model="editing[key]" class="input font-mono" maxlength="100" placeholder="lettres minuscules, chiffres et _" />
                </Field>
                <div class="flex gap-2">
                  <button class="btn-brand" :disabled="busy === key">Enregistrer</button>
                  <button type="button" class="btn-ghost" @click="delete editing[key]">Annuler</button>
                </div>
              </form>
              <p v-else class="text-xs text-slate-500">
                Modèle chez Meta : <span class="font-mono">{{ templateName(key) || 'aucun' }}</span> ·
                <button type="button" class="font-medium text-emerald-700 underline" @click="editing[key] = templateName(key)">Modifier</button>
              </p>
            </div>
          </li>
        </ul>
      </section>
    </template>

    <!-- Connexion avancée : identifiants Meta à la main, guide pas à pas, webhook -->
    <Modal v-if="advanced" title="Connexion avancée (identifiants Meta)" wide @close="advanced = false">
      <div class="space-y-5 text-sm">
        <details v-if="!account" class="rounded-xl bg-slate-50 p-3">
          <summary class="cursor-pointer font-semibold">Créer votre accès chez Meta, étape par étape (environ 30 minutes)</summary>
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
          </ol>
        </details>
        <form class="space-y-4" @submit.prevent="saveAdvanced">
          <div class="grid gap-4 sm:grid-cols-2">
            <Field id="wa-phone-id" label="Identifiant du numéro (Phone number ID)" :error="formErrors.phone_number_id">
              <input id="wa-phone-id" v-model="form.phone_number_id" class="input" maxlength="40" required :disabled="embedded" />
            </Field>
            <Field id="wa-display" label="Numéro affiché" :error="formErrors.display_phone">
              <input id="wa-display" v-model="form.display_phone" class="input" maxlength="20" placeholder="+225 07 00 00 00 00" />
            </Field>
            <Field id="wa-waba" label="Identifiant du compte WhatsApp Business (WABA ID)" :error="formErrors.waba_id">
              <input id="wa-waba" v-model="form.waba_id" class="input" maxlength="40" :disabled="embedded" />
            </Field>
            <template v-if="!embedded">
              <Field id="wa-token" label="Jeton d'accès permanent" :error="formErrors.access_token" :hint="account ? 'Laisser vide pour garder le jeton enregistré.' : ''">
                <input id="wa-token" v-model="form.access_token" class="input" type="password" autocomplete="off" :required="!account" />
              </Field>
              <Field id="wa-secret" label="Clé secrète de l'application Meta" :error="formErrors.app_secret" :hint="account ? 'Laisser vide pour garder la clé enregistrée.' : 'Sert à vérifier que les notifications viennent bien de Meta.'">
                <input id="wa-secret" v-model="form.app_secret" class="input" type="password" autocomplete="off" :required="!account" />
              </Field>
            </template>
          </div>
          <div v-if="account && !embedded" class="space-y-2 rounded-xl bg-slate-50 p-3">
            <p class="text-slate-600">À coller dans votre application Meta (WhatsApp › Configuration › Webhook, champ « messages ») :</p>
            <div class="flex items-center gap-2"><span class="w-32 shrink-0 text-slate-500">URL de rappel</span><code class="min-w-0 flex-1 truncate">{{ account.webhook_url }}</code><button type="button" class="btn-ghost px-2 py-1" @click="copy(account.webhook_url)">Copier</button></div>
            <div class="flex items-center gap-2"><span class="w-32 shrink-0 text-slate-500">Jeton de vérification</span><code class="min-w-0 flex-1 truncate">{{ account.verify_token }}</code><button type="button" class="btn-ghost px-2 py-1" @click="copy(account.verify_token)">Copier</button></div>
          </div>
          <div class="flex flex-wrap gap-2">
            <button class="btn-brand" :disabled="formBusy">{{ account ? 'Enregistrer' : 'Connecter WhatsApp' }}</button>
            <button type="button" class="btn-ghost" @click="advanced = false">Fermer</button>
          </div>
        </form>
      </div>
    </Modal>
  </div>
</template>
