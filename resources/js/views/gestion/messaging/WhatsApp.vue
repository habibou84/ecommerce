<script setup>
import WhatsAppHelp from '../../../components/WhatsAppHelp.vue'
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import ColorIcon from '../../../components/ColorIcon.vue'
import WhatsAppHub from '../../../components/WhatsAppHub.vue'
import WhatsAppMetaHealth from '../../../components/WhatsAppMetaHealth.vue'
import http, { errorMessage } from '../../../lib/http'
import { boot } from '../../../lib/boot'
import { facebookRelayUrl, openFacebookRelay, takeFacebookResult } from '../../../lib/facebook'
import { formatDateTime } from '../../../lib/format'
import { useAuthStore } from '../../../stores/auth'

/**
 * Menu WhatsApp › Accueil : votre numéro (connexion, état chez Meta, messages du téléphone bien reçus), ce qui attend,
 * puis les entrées du menu (conversations, assistant des ventes, assistant des réponses, relances, textes).
 * Les anciens onglets de Réglages › WhatsApp (?onglet=…) mènent à leur nouvelle page.
 */
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const official = ref(null) // GET /whatsapp
const sales = ref(null) // GET /whatsapp/sales
const error = ref('')

const OLD_TABS = { modeles: '/gestion/whatsapp/ventes', catalogue: '/gestion/whatsapp/ventes', assistant: '/gestion/whatsapp/reponses', historique: '/gestion/whatsapp/reponses#historique' }

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

onMounted(async () => {
  const old = OLD_TABS[String(route.query.onglet ?? '')]
  if (old && !facebook) return router.replace(old)
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
  } catch (e) {
    error.value = errorMessage(e)
  }
})

// Connexion du numéro : « Connecter avec Facebook », ou numéro connecté avec « Déconnecter »
const connecting = ref(false)
const disconnecting = ref(false)
function connect() {
  const e = official.value?.embedded
  if (!e) return router.push('/gestion/whatsapp/reponses#messages') // sans connexion en un clic : connexion avancée
  connecting.value = true
  // Page d'avant (live, commandes…) : on y revient une fois connecté
  const before = window.history.state?.back
  const from = typeof before === 'string' && before.startsWith('/gestion') && !before.startsWith('/gestion/whatsapp') ? window.location.origin + before : null
  openFacebookRelay(facebookRelayUrl(boot.platformUrl, { appId: e.app_id, configId: e.config_id, version: e.graph_version, coexistence: 'auto' }, window.location.origin + window.location.pathname, from), window.location.pathname)
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
// Messages écrits depuis le téléphone (application WhatsApp Business) bien recopiés : « récap » et « confirmé » marchent
const phoneEcho = computed(() => sales.value?.phone_message_at ? `Message du téléphone reçu le ${formatDateTime(sales.value.phone_message_at)}` : null)
const canMessages = computed(() => auth.can('orders.manage') || auth.can('orders.confirm'))
</script>

<template>
  <div v-if="official && sales" class="space-y-4 pb-24 sm:pb-0">
    <!-- Mon numéro : connexion ou déconnexion, état chez Meta -->
    <section id="numero" class="card p-4 sm:p-5">
      <div class="flex items-center gap-3">
        <ColorIcon name="whatsapp" :size="44" />
        <div class="min-w-0 flex-1">
          <h2 class="text-[17px] font-extrabold">Mon numéro</h2>
          <p class="text-sm text-slate-500">{{ official.account ? `${official.account.display_phone ?? ''}${official.account.verified_name ? ` · « ${official.account.verified_name} »` : ''}` : 'Aucun numéro WhatsApp connecté' }}</p>
        </div>
      </div>
      <!-- Retour de Facebook : connexion en cours, puis résultat -->
      <div v-if="finishing" class="mt-3 flex items-center gap-3 rounded-xl bg-sky-50 p-4 text-sky-950" role="status">
        <span class="h-6 w-6 shrink-0 animate-spin rounded-full border-[3px] border-sky-200 border-t-sky-700" aria-hidden="true" />
        <span class="text-[15px] font-bold">Connexion de votre WhatsApp en cours…<span class="block text-sm font-normal">Quelques secondes : ne fermez pas la page.</span></span>
      </div>
      <div v-else-if="finished" class="mt-3 rounded-xl bg-emerald-50 p-4 text-[15px] font-bold text-emerald-900" role="status">{{ finished }}
        <ul v-if="warnings.length" class="mt-1 list-disc space-y-1 pl-5 text-sm font-normal text-ocre-900"><li v-for="w in warnings" :key="w">{{ w }}</li></ul>
      </div>
      <p v-if="error" class="mt-3 rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-800" role="alert">{{ error }}</p>
      <div v-if="!finishing" class="mt-3 flex flex-col gap-3 rounded-xl p-3 sm:flex-row sm:items-center" :class="official.account ? 'bg-emerald-50' : 'bg-ocre-50'">
        <span class="min-w-0 flex-1 text-sm">
          <template v-if="official.account">
            <strong class="block">Numéro connecté {{ official.account.display_phone || '' }}</strong>
            <span class="text-xs text-slate-600">{{ official.account.connection === 'embedded' ? 'Connecté avec Facebook' : 'Connecté avec vos identifiants Meta' }}<template v-if="phoneEcho"> · {{ phoneEcho }}</template></span>
          </template>
          <template v-else>
            <strong class="block">Connectez votre numéro WhatsApp</strong>
            <span class="text-xs text-slate-600">Connexion officielle chez Meta : messages des clients dans Conversations, récap, catalogue et messages automatiques.</span>
          </template>
        </span>
        <button v-if="!official.account" type="button" class="btn-brand w-full sm:w-auto" :disabled="connecting" @click="connect">{{ connecting ? 'Connexion…' : (official.embedded ? 'Connecter mon numéro sur Meta' : 'Connecter mon numéro') }}</button>
        <button v-else type="button" class="btn-ghost self-start text-red-700 sm:self-auto" :disabled="disconnecting" @click="disconnect">{{ disconnecting ? 'Déconnexion…' : 'Déconnecter' }}</button>
      </div>
      <WhatsAppHelp v-if="!official.account && official.embedded" class="mt-2" />
    </section>

    <!-- État chez Meta : compte, nom, paiement, qualité ; ce qui bloque et où agir -->
    <WhatsAppMetaHealth v-if="official.account?.meta" :meta="official.account.meta" @update="official = $event" />

    <WhatsAppHub :data="sales" :can-messages="canMessages" />
  </div>
  <p v-else-if="!error" class="text-sm text-slate-500">Chargement…</p>
  <Alert v-else>{{ error }}</Alert>
</template>
