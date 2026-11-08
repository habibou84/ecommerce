<script setup>
import WhatsAppHelp from '../../../components/WhatsAppHelp.vue'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import ColorIcon from '../../../components/ColorIcon.vue'
import Icon from '../../../components/Icon.vue'
import WhatsAppAssistant from '../../../components/WhatsAppAssistant.vue'
import WhatsAppAutomations from '../../../components/WhatsAppAutomations.vue'
import WhatsAppCatalog from '../../../components/WhatsAppCatalog.vue'
import WhatsAppMetaHealth from '../../../components/WhatsAppMetaHealth.vue'
import http, { errorMessage } from '../../../lib/http'
import { boot } from '../../../lib/boot'
import { facebookRelayUrl, takeFacebookResult } from '../../../lib/facebook'
import { formatDateTime } from '../../../lib/format'

/**
 * Réglages › WhatsApp : un résumé (ce qui marche, ce qui manque), puis un onglet par sujet — conversations (numéro
 * officiel), messages automatiques (numéro officiel), assistant, catalogue, historique des envois.
 */
const route = useRoute()
const router = useRouter()
const official = ref(null) // GET /whatsapp
const sales = ref(null) // GET /whatsapp/sales
const error = ref('')

const TABS = [
  { key: 'conversations', label: 'Conversations' },
  { key: 'messages', label: 'Messages automatiques' },
  { key: 'assistant', label: 'Assistant' },
  { key: 'modeles', label: 'Modèles' },
  { key: 'catalogue', label: 'Catalogue' },
  { key: 'historique', label: 'Historique' },
]
// Retour de la fenêtre Facebook (#whatsapp…) : onglet des messages automatiques
const tab = ref(window.location.hash.startsWith('#whatsapp') ? 'messages' : String(route.query.onglet ?? ''))
watch(tab, (t) => router.replace({ query: { ...route.query, onglet: t } }))

// Retour de Facebook : la connexion se termine ici, en grand en haut de la page, puis retour à la page de départ
const facebook = takeFacebookResult()
const finishing = ref(!!facebook?.code)
const finished = ref('')
const warnings = ref([])
function startPage() {
  let after = null
  try {
    after = sessionStorage.getItem('jm.after-whatsapp')
    sessionStorage.removeItem('jm.after-whatsapp')
  } catch {
    after = null
  }
  if (!after && facebook?.depuis) {
    try {
      const url = new URL(facebook.depuis)
      after = url.origin === window.location.origin ? url.pathname + url.search : null
    } catch {
      after = null
    }
  }
  return after?.startsWith('/gestion') && !after.startsWith('/gestion/whatsapp') ? after : null
}
async function finishFacebook() {
  try {
    const { depuis, ...body } = facebook
    const response = (await http.post('/whatsapp/embedded', body)).data.data
    official.value = response
    warnings.value = response.warnings ?? []
    const after = startPage()
    finished.value = after ? 'WhatsApp connecté ✓ Retour à votre page…' : 'WhatsApp connecté ✓'
    // Toujours retour à la page de départ, même avec des avertissements de Meta (ils restent visibles ici)
    if (after) {
      try {
        sessionStorage.setItem('jm.whatsapp-connected', '1')
      } catch {
        // Sans stockage : pas de petit message au retour
      }
      setTimeout(() => router.push(after), warnings.value.length ? 2500 : 1500)
    }
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    finishing.value = false
  }
}
// Catalogue WhatsApp : seulement quand la console l'a allumé
const tabs = computed(() => TABS.filter((t) => t.key !== 'catalogue' || sales.value?.catalog))

onMounted(async () => {
  try {
    const [o, s] = await Promise.all([http.get('/whatsapp'), http.get('/whatsapp/sales')])
    official.value = o.data.data
    sales.value = s.data.data
    if (facebook?.code) finishFacebook()
    else if (facebook?.error) {
      const after = startPage()
      if (after) return router.replace(after)
      error.value = facebook.error
    }
    // Sans onglet demandé : le premier sujet à régler
    if (!tabs.value.some((t) => t.key === tab.value)) {
      const first = summary.value.find((r) => r.todo)
      tab.value = first ? (first.to ?? first.key) : 'conversations'
    }
  } catch (e) {
    error.value = errorMessage(e)
  }
})

// Connexion du numéro en haut de la page : « Connecter avec Facebook », ou numéro connecté avec « Déconnecter »
const connecting = ref(false)
const disconnecting = ref(false)
function connect() {
  const e = official.value?.embedded
  if (!e) return (tab.value = 'messages') // sans connexion en un clic : connexion avancée dans l'onglet
  connecting.value = true
  // Page d'avant (live, commandes…) : on y revient une fois connecté
  const before = window.history.state?.back
  const from = typeof before === 'string' && before.startsWith('/gestion') && !before.startsWith('/gestion/whatsapp') ? window.location.origin + before : null
  window.location.assign(facebookRelayUrl(boot.platformUrl, { appId: e.app_id, configId: e.config_id, version: e.graph_version, coexistence: 'auto' }, window.location.origin + window.location.pathname, from))
}
async function disconnect() {
  const a = official.value?.account
  if (!window.confirm(`Déconnecter le numéro ${a?.display_phone ?? ''} ? Les messages de vos clients n'arriveront plus dans Messages et les messages automatiques s'arrêtent.`)) return
  disconnecting.value = true
  error.value = ''
  try {
    official.value = (await http.delete('/whatsapp')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    disconnecting.value = false
  }
}

const activeMessages = computed(() => {
  const a = official.value?.account
  if (!a) return 0
  return Object.keys(official.value.events).filter((k) => !official.value.events[k].manual && a.automations?.[k] && a.templates?.[k]).length
})

// « Montrer mes modèles aux clients »
const savingModels = ref(false)
async function saveModels(enabled) {
  savingModels.value = true
  try {
    sales.value = (await http.put('/whatsapp/models', { enabled })).data.data
  } finally {
    savingModels.value = false
  }
}

// Résumé : une ligne par sujet, au style de « À faire maintenant » de l'accueil
const summary = computed(() => {
  if (!official.value || !sales.value) return []
  const a = official.value.account
  const pending = a ? Object.values(a.template_status ?? {}).filter((s) => s === 'PENDING').length : 0
  const c = sales.value.catalog
  const auto = sales.value.auto_replies
  return [
    {
      key: 'conversations', icon: 'telephone', label: 'Conversations',
      // Les conversations passent par le numéro officiel (plus de liaison par QR code sur cette page)
      ...(a ? { ok: true, state: `Par votre numéro officiel${a.display_phone ? ` ${a.display_phone}` : ''}` } : { todo: true, state: 'Connectez votre numéro officiel', to: 'messages' }),
    },
    {
      key: 'messages', icon: 'cloche', label: 'Messages automatiques',
      ...(!a ? { todo: true, state: 'Pas encore connecté' }
        : a.status === 'disabled' ? { todo: true, state: 'En pause' }
          : a.meta_number ? { todo: true, state: 'Numéro fourni par Meta : connectez le vôtre' }
            : a.last_error ? { todo: true, state: a.error_help?.title ?? 'Erreur de Meta à regarder' }
              : { ok: activeMessages.value > 0, state: `${activeMessages.value} message(s) actif(s)${pending ? ` · ${pending} en attente chez Meta` : ''}` }),
    },
    {
      key: 'assistant', icon: 'aide', label: 'Assistant (réponses automatiques)',
      ...(auto.enabled ? { ok: true, state: `Activé · ${sales.value.sent_last_7_days} réponse(s) en 7 jours` } : { state: 'Désactivé' }),
    },
    sales.value.models && {
      key: 'modeles', icon: 'produit', label: 'Modèles montrés aux clients',
      ...(sales.value.models.enabled ? { ok: true, state: `Activé · ${sales.value.models.orders_last_30_days} commande(s) en 30 jours` } : { state: 'Désactivé' }),
    },
    c && {
      key: 'catalogue', icon: 'produit', label: 'Catalogue WhatsApp',
      ...(c.meta ? { ok: true, state: `Relié à Meta · ${c.items} article(s)` } : { todo: c.items > 0, state: `${c.items} article(s) prêt(s) · à relier à Meta` }),
    },
  ].filter(Boolean)
})
const running = computed(() => summary.value.filter((r) => r.ok).length)

// Historique des messages envoyés par le numéro officiel
const STATUS = { queued: ['En file', 'bg-slate-100 text-slate-700'], sent: ['Envoyé', 'bg-slate-100 text-slate-700'], delivered: ['Reçu', 'bg-sky-100 text-sky-800'], read: ['Lu', 'bg-emerald-100 text-emerald-800'], failed: ['Échec', 'bg-red-100 text-red-800'] }
const FILTERS = [['all', 'Tous'], ['read', 'Lus'], ['failed', 'Échecs']]
const filter = ref('all')
const messages = computed(() => (official.value?.messages ?? []).filter((m) => filter.value === 'all' || m.status === filter.value))
const SHORT = { order_confirmation: 'Demande de confirmation', courier_on_the_way: 'Livreur en route', order_shipped: 'Commande en route', order_expedited: 'Colis expédié', order_delivered: 'Commande livrée', review_request: 'Demande d\'avis', payment_link: 'Lien de paiement', cart_recovery: 'Panier abandonné', campaign: 'Relance des clients', daily_tips: 'Conseils du soir', daily_report: 'Rapport du jour' }
</script>

<template>
  <div v-if="official && sales" class="space-y-5 pb-24 sm:pb-0">
    <!-- Résumé : ce qui marche, ce qui manque -->
    <section class="card p-4 sm:p-5">
      <div class="flex items-center gap-3">
        <ColorIcon name="whatsapp" :size="44" />
        <div class="min-w-0 flex-1">
          <h2 class="text-[17px] font-extrabold">Votre WhatsApp</h2>
          <p class="text-sm text-slate-500">{{ running }} sur {{ summary.length }} en marche · touchez une ligne pour la régler</p>
        </div>
      </div>
      <!-- Retour de Facebook : connexion en cours, puis résultat -->
      <div v-if="finishing" class="mt-3 flex items-center gap-3 rounded-xl bg-sky-50 p-4 text-sky-950" role="status">
        <span class="h-6 w-6 shrink-0 animate-spin rounded-full border-[3px] border-sky-200 border-t-sky-700" aria-hidden="true" />
        <span class="text-[15px] font-bold">Connexion de votre WhatsApp en cours…<span class="block text-sm font-normal">Quelques secondes : ne fermez pas la page.</span></span>
      </div>
      <div v-else-if="finished" class="mt-3 rounded-xl bg-emerald-50 p-4 text-[15px] font-bold text-emerald-900" role="status">{{ finished }}
        <ul v-if="warnings.length" class="mt-1 list-disc space-y-1 pl-5 text-sm font-normal text-amber-900"><li v-for="w in warnings" :key="w">{{ w }}</li></ul>
      </div>
      <p v-if="error" class="mt-3 rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-800" role="alert">{{ error }}</p>
      <!-- Numéro : connexion ou déconnexion, sans chercher dans les onglets -->
      <div v-if="!finishing" class="mt-3 flex flex-col gap-3 rounded-xl p-3 sm:flex-row sm:items-center" :class="official.account ? 'bg-emerald-50' : 'bg-amber-50'">
        <span class="min-w-0 flex-1 text-sm">
          <template v-if="official.account">
            <strong class="block">Numéro connecté {{ official.account.display_phone || '' }}</strong>
            <span class="text-xs text-slate-600">{{ official.account.verified_name ? `« ${official.account.verified_name} » · ` : '' }}{{ official.account.connection === 'embedded' ? 'Connecté avec Facebook' : 'Connecté avec vos identifiants Meta' }}</span>
          </template>
          <template v-else>
            <strong class="block">Aucun numéro WhatsApp connecté</strong>
            <span class="text-xs text-slate-600">Connexion officielle de votre numéro chez Meta : messages des clients dans Messages et messages automatiques.</span>
          </template>
        </span>
        <button v-if="!official.account" type="button" class="btn-brand w-full sm:w-auto" :disabled="connecting" @click="connect">{{ connecting ? 'Connexion…' : (official.embedded ? 'Connecter mon numéro sur Meta' : 'Connecter mon numéro') }}</button>
        <button v-else type="button" class="btn-ghost self-start text-red-600 sm:self-auto" :disabled="disconnecting" @click="disconnect">{{ disconnecting ? 'Déconnexion…' : 'Déconnecter' }}</button>
      </div>
      <WhatsAppHelp v-if="!official.account && official.embedded" class="mt-2" />
      <ul class="mt-3 grid gap-x-6 sm:grid-cols-2">
        <li v-for="s in summary" :key="s.key" class="border-t border-slate-100">
          <button type="button" class="-mx-2 flex w-full items-center gap-3 rounded-xl px-2 py-3 text-left hover:bg-slate-50" @click="tab = s.to ?? s.key">
            <ColorIcon :name="s.icon" :size="38" :class="s.ok || s.todo ? '' : 'opacity-60'" />
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-semibold text-slate-900">{{ s.label }}</span>
              <span class="block text-xs" :class="s.ok ? 'text-emerald-700' : s.todo ? 'text-ocre-700' : 'text-slate-500'">{{ s.ok ? '✓ ' : '' }}{{ s.state }}</span>
            </span>
            <Icon name="chevron" :size="16" class="text-slate-400" />
          </button>
        </li>
      </ul>
    </section>

    <!-- État chez Meta : compte, nom, paiement, qualité ; ce qui bloque et où agir -->
    <WhatsAppMetaHealth v-if="official.account?.meta" :meta="official.account.meta" @update="official = $event" />

    <!-- Onglets, comme Marketing, Livraisons, Stock -->
    <nav v-tab-strip class="-mx-4 flex gap-1 overflow-x-auto border-b border-slate-200 px-4 sm:mx-0 sm:px-0" role="tablist">
      <button v-for="t in tabs" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key" class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"
              :class="tab === t.key ? 'border-emerald-700 text-emerald-800' : 'border-transparent text-slate-600 hover:text-slate-900'" @click="tab = t.key">{{ t.label }}</button>
    </nav>

    <!-- Conversations : par le numéro officiel -->
    <section v-if="tab === 'conversations'" class="card space-y-4 p-4 sm:p-5">
      <div class="flex items-start gap-3">
        <ColorIcon name="telephone" :size="44" />
        <div class="min-w-0 flex-1">
          <h3 class="text-[17px] font-extrabold">Conversations clients dans Messages</h3>
          <p class="text-sm text-slate-600">
            {{ official.account ? 'Les messages de vos clients arrivent par votre numéro officiel dans Messages, et vous y répondez.' : 'Connectez votre numéro WhatsApp officiel : les messages de vos clients arriveront dans Messages et vous y répondrez.' }}
          </p>
        </div>
      </div>
      <RouterLink v-if="official.account" to="/gestion/messages" class="btn-secondary">Ouvrir Messages</RouterLink>
      <button v-else type="button" class="btn-brand" :disabled="connecting" @click="connect">{{ connecting ? 'Connexion…' : 'Connecter avec Facebook' }}</button>
    </section>
    <WhatsAppAutomations v-else-if="tab === 'messages'" :data="official" @update="official = $event" />
    <WhatsAppAssistant v-else-if="tab === 'assistant'" :data="sales" @update="sales = $event" />
    <WhatsAppCatalog v-else-if="tab === 'catalogue'" :data="sales" />
    <!-- Modèles : le client écrit « modèles » et reçoit les photos, puis commande en touchant -->
    <section v-else-if="tab === 'modeles' && sales.models" class="card space-y-4 p-4 sm:p-5">
      <div class="flex items-start gap-3">
        <ColorIcon name="produit" :size="44" />
        <div class="min-w-0 flex-1">
          <h3 class="text-[17px] font-extrabold">Montrer mes modèles aux clients</h3>
          <p class="text-sm text-slate-600">Un client écrit « modèles » ou « vous avez quoi ? » : il reçoit vos catégories, puis les photos 5 par 5 avec le prix et les tailles en stock. Il commande en touchant « Je prends ».</p>
        </div>
      </div>
      <label class="flex items-center gap-3 rounded-xl bg-slate-50 p-3">
        <input type="checkbox" class="h-5 w-5" :checked="sales.models.enabled" :disabled="savingModels" @change="saveModels($event.target.checked)" />
        <span class="text-sm font-medium">{{ sales.models.enabled ? 'Activé' : 'Désactivé' }}</span>
        <span class="ml-auto text-sm text-slate-600">{{ sales.models.orders_last_30_days }} commande(s) en 30 jours</span>
      </label>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
        <li>Les catégories viennent de vos collections, avec « Packs » et « Nouveautés ».</li>
        <li>Si vous répondez vous-même dans la conversation, l'assistant se tait pendant 6 h.</li>
        <li>Pendant un live, « LIVE » montre toujours les articles du live.</li>
        <li>Ces commandes ne comptent pas dans le prix du live.</li>
      </ul>
    </section>

    <section v-else-if="tab === 'historique'" class="card p-4 sm:p-5">
      <div class="flex flex-wrap gap-1.5 text-sm" role="radiogroup" aria-label="Filtrer les messages">
        <button v-for="[key, text] in FILTERS" :key="key" type="button" role="radio" :aria-checked="filter === key" class="rounded-full px-3 py-1 font-medium"
                :class="filter === key ? 'bg-emerald-700 text-white' : 'bg-slate-100 text-slate-700'" @click="filter = key">{{ text }}</button>
      </div>
      <ul class="mt-2 divide-y divide-slate-100">
        <li v-for="m in messages" :key="m.id" class="flex items-center gap-3 py-3 text-sm">
          <span class="min-w-0 flex-1">
            <span class="block font-semibold">{{ SHORT[m.event] ?? official.events[m.event]?.label ?? m.event }}</span>
            <span class="block text-xs text-slate-500">{{ m.to }} · {{ formatDateTime(m.created_at) }}<template v-if="m.error"> · <span class="text-red-700">{{ m.error_help || m.error }}</span></template></span>
          </span>
          <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium" :class="(STATUS[m.status] ?? STATUS.sent)[1]">{{ (STATUS[m.status] ?? [m.status])[0] }}</span>
          <RouterLink v-if="m.related_type === 'order'" :to="`/gestion/commandes/${m.related_id}`" class="shrink-0 text-slate-400 hover:text-emerald-700" aria-label="Voir la commande"><Icon name="chevron" :size="16" /></RouterLink>
        </li>
        <li v-if="!messages.length" class="py-6 text-center text-slate-500">
          <template v-if="filter !== 'all'">Aucun message dans ce filtre.</template>
          <template v-else-if="official.account">Aucun message envoyé pour l'instant : le premier partira à la prochaine commande.</template>
          <template v-else>Les messages envoyés par votre numéro officiel s'afficheront ici. <button type="button" class="font-medium text-emerald-700 underline" @click="tab = 'messages'">Connecter le numéro</button></template>
        </li>
      </ul>
    </section>
  </div>
  <p v-else-if="!error" class="text-sm text-slate-500">Chargement…</p>
  <Alert v-else>{{ error }}</Alert>
</template>
