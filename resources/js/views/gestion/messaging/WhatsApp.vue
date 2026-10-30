<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import ColorIcon from '../../../components/ColorIcon.vue'
import Icon from '../../../components/Icon.vue'
import WhatsAppAssistant from '../../../components/WhatsAppAssistant.vue'
import WhatsAppAutomations from '../../../components/WhatsAppAutomations.vue'
import WhatsAppCatalog from '../../../components/WhatsAppCatalog.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime } from '../../../lib/format'

/**
 * Réglages › WhatsApp : un résumé (ce qui marche, ce qui manque), puis un onglet par sujet — conversations (WhatsApp
 * relié par QR code), messages automatiques (numéro officiel), assistant, catalogue, historique des envois.
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
  { key: 'catalogue', label: 'Catalogue' },
  { key: 'historique', label: 'Historique' },
]
// Retour de la fenêtre Facebook (#whatsapp…) : onglet des messages automatiques
const tab = ref(window.location.hash.startsWith('#whatsapp') ? 'messages' : String(route.query.onglet ?? ''))
watch(tab, (t) => router.replace({ query: { ...route.query, onglet: t } }))

onMounted(async () => {
  try {
    const [o, s] = await Promise.all([http.get('/whatsapp'), http.get('/whatsapp/sales')])
    official.value = o.data.data
    sales.value = s.data.data
    // Sans onglet demandé : le premier sujet à régler
    if (!TABS.some((t) => t.key === tab.value)) {
      const first = summary.value.find((r) => r.todo)
      tab.value = first ? (first.to ?? first.key) : 'conversations'
    }
  } catch (e) {
    error.value = errorMessage(e)
  }
})

const activeMessages = computed(() => {
  const a = official.value?.account
  if (!a) return 0
  return Object.keys(official.value.events).filter((k) => !official.value.events[k].manual && a.automations?.[k] && a.templates?.[k]).length
})

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
    {
      key: 'catalogue', icon: 'produit', label: 'Catalogue WhatsApp',
      ...(c.meta ? { ok: true, state: `Relié à Meta · ${c.items} article(s)` } : { todo: c.items > 0, state: `${c.items} article(s) prêt(s) · à relier à Meta` }),
    },
  ]
})
const running = computed(() => summary.value.filter((r) => r.ok).length)

// Historique des messages envoyés par le numéro officiel
const STATUS = { queued: ['En file', 'bg-slate-100 text-slate-700'], sent: ['Envoyé', 'bg-slate-100 text-slate-700'], delivered: ['Reçu', 'bg-sky-100 text-sky-800'], read: ['Lu', 'bg-emerald-100 text-emerald-800'], failed: ['Échec', 'bg-red-100 text-red-800'] }
const FILTERS = [['all', 'Tous'], ['read', 'Lus'], ['failed', 'Échecs']]
const filter = ref('all')
const messages = computed(() => (official.value?.messages ?? []).filter((m) => filter.value === 'all' || m.status === filter.value))
const SHORT = { order_confirmation: 'Demande de confirmation', courier_on_the_way: 'Livreur en route', order_shipped: 'Commande en route', order_delivered: 'Commande livrée', review_request: 'Demande d\'avis', payment_link: 'Lien de paiement', cart_recovery: 'Panier abandonné', campaign: 'Relance des clients', daily_tips: 'Conseils du soir', daily_report: 'Rapport du jour' }
</script>

<template>
  <div v-if="official && sales" class="space-y-5 pb-24 sm:pb-0">
    <!-- Résumé : ce qui marche, ce qui manque -->
    <section class="card p-4 sm:p-5">
      <div class="flex items-center gap-3">
        <ColorIcon name="whatsapp" :size="44" />
        <div class="min-w-0 flex-1">
          <h2 class="text-[17px] font-extrabold">Votre WhatsApp</h2>
          <p class="text-sm text-slate-500">{{ running }} sur 4 en marche · touchez une ligne pour la régler</p>
        </div>
      </div>
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

    <!-- Onglets, comme Marketing, Livraisons, Stock -->
    <nav v-tab-strip class="-mx-4 flex gap-1 overflow-x-auto border-b border-slate-200 px-4 sm:mx-0 sm:px-0" role="tablist">
      <button v-for="t in TABS" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key" class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"
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
      <button v-else type="button" class="btn-brand" @click="tab = 'messages'">Connecter le numéro officiel</button>
    </section>
    <WhatsAppAutomations v-else-if="tab === 'messages'" :data="official" @update="official = $event" />
    <WhatsAppAssistant v-else-if="tab === 'assistant'" :data="sales" @update="sales = $event" />
    <WhatsAppCatalog v-else-if="tab === 'catalogue'" :data="sales" />

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
