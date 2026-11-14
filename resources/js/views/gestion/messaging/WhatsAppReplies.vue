<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import ColorIcon from '../../../components/ColorIcon.vue'
import Icon from '../../../components/Icon.vue'
import WhatsAppAssistant from '../../../components/WhatsAppAssistant.vue'
import WhatsAppAutomations from '../../../components/WhatsAppAutomations.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime } from '../../../lib/format'

/**
 * WhatsApp › Assistant des réponses : il répond à votre place aux questions simples (vrais prix, vrai stock), donne
 * le prix de la livraison, et envoie les messages automatiques à chaque étape de la commande ; puis l'historique des
 * messages envoyés. Les assistants se taisent dès que vous répondez, depuis l'application ou votre téléphone.
 */
const official = ref(null) // GET /whatsapp
const sales = ref(null) // GET /whatsapp/sales
const error = ref('')

onMounted(async () => {
  try {
    const [o, s] = await Promise.all([http.get('/whatsapp'), http.get('/whatsapp/sales')])
    official.value = o.data.data
    sales.value = s.data.data
    // Lien vers une section (#messages, #historique)
    await nextTick()
    if (window.location.hash) document.getElementById(window.location.hash.slice(1))?.scrollIntoView({ block: 'start' })
  } catch (e) {
    error.value = errorMessage(e)
  }
})

// Historique des messages envoyés par le numéro officiel
const STATUS = { queued: ['En file', 'bg-slate-100 text-slate-700'], sent: ['Envoyé', 'bg-slate-100 text-slate-700'], delivered: ['Reçu', 'bg-sky-100 text-sky-800'], read: ['Lu', 'bg-emerald-100 text-emerald-800'], failed: ['Échec', 'bg-red-100 text-red-800'] }
const FILTERS = [['all', 'Tous'], ['read', 'Lus'], ['failed', 'Échecs']]
const filter = ref('all')
const messages = computed(() => (official.value?.messages ?? []).filter((m) => filter.value === 'all' || m.status === filter.value))
const SHORT = { order_confirmation: 'Demande de confirmation', courier_on_the_way: 'Livreur en route', order_shipped: 'Commande en route', order_expedited: 'Colis expédié', order_delivered: 'Commande livrée', review_request: 'Demande d\'avis', payment_link: 'Lien de paiement', cart_recovery: 'Panier abandonné', campaign: 'Relance des clients', daily_tips: 'Conseils du soir', daily_report: 'Rapport du jour' }
</script>

<template>
  <div v-if="official && sales" class="space-y-4 pb-24 sm:pb-0">
    <p class="flex items-start gap-2 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-900">
      <Icon name="help" :size="18" class="mt-0.5 shrink-0" />
      <span>Dès que vous répondez à un client, depuis l'application ou depuis votre téléphone, les assistants se taisent pour lui.</span>
    </p>

    <!-- Réponses automatiques -->
    <WhatsAppAssistant :data="sales" @update="sales = $event" />

    <!-- Prix de la livraison : toujours en marche, rien à régler ici -->
    <section class="card flex items-start gap-3 p-4 sm:p-5">
      <ColorIcon name="moto" :size="44" />
      <div class="min-w-0 flex-1">
        <h2 class="text-[17px] font-extrabold">Prix de livraison</h2>
        <p class="text-sm text-slate-600">« C'est combien la livraison à Yop ? » : le client reçoit le prix et les quartiers, à partir de vos tarifs.</p>
      </div>
      <RouterLink to="/gestion/livraisons/tarifs" class="btn-secondary shrink-0">Mes tarifs</RouterLink>
    </section>

    <!-- Messages automatiques à chaque étape de la commande -->
    <div id="messages"><WhatsAppAutomations :data="official" @update="official = $event" /></div>

    <RouterLink to="/gestion/textes-messages" class="card flex items-center gap-3 px-4 py-3">
      <ColorIcon name="cloche" :size="36" />
      <span class="min-w-0 flex-1"><b class="block text-[15px]">Textes des messages</b><span class="text-[13px] text-slate-500">Changer les mots de chaque message</span></span>
      <Icon name="chevron" :size="16" class="text-slate-400" />
    </RouterLink>

    <section id="historique" class="card p-4 sm:p-5">
      <h2 class="text-[17px] font-extrabold">Messages envoyés</h2>
      <div class="mt-2 flex flex-wrap gap-1.5 text-sm" role="radiogroup" aria-label="Filtrer les messages">
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
          <template v-else>Les messages envoyés par votre numéro officiel s'afficheront ici. <RouterLink to="/gestion/whatsapp" class="font-medium text-emerald-700 underline">Connecter le numéro</RouterLink></template>
        </li>
      </ul>
    </section>
  </div>
  <p v-else-if="!error" class="text-sm text-slate-500">Chargement…</p>
  <Alert v-else>{{ error }}</Alert>
</template>
