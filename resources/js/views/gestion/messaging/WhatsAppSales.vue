<script setup>
import { computed, onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import ColorIcon from '../../../components/ColorIcon.vue'
import Icon from '../../../components/Icon.vue'
import StateBadge from '../../../components/StateBadge.vue'
import ToggleSwitch from '../../../components/ToggleSwitch.vue'
import WhatsAppCatalog from '../../../components/WhatsAppCatalog.vue'
import http, { errorMessage } from '../../../lib/http'
import { money } from '../../../lib/format'

/**
 * WhatsApp › Assistant des ventes : les commandes prises dans WhatsApp s'enregistrent toutes seules. Récap écrit par
 * le marchand depuis son téléphone, catalogue WhatsApp (trois étapes, puis commune et récap après un panier), modèles
 * (« modèles » écrit par le client), commandes avec codes sans live, et les dernières commandes WhatsApp.
 */
const data = ref(null) // GET /whatsapp/sales
const error = ref('')
const busy = ref('')

onMounted(async () => {
  try {
    data.value = (await http.get('/whatsapp/sales')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
})
async function save(key, url, body) {
  busy.value = key
  error.value = ''
  try {
    data.value = (await http.put(url, body)).data.data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = ''
  }
}
const ORIGINS = { recap: 'Récap', catalog: 'Catalogue', models: 'Modèles' }
const codes = computed(() => data.value?.codes)
</script>

<template>
  <div v-if="data" class="space-y-4 pb-24 sm:pb-0">
    <p class="text-sm text-slate-600">Les commandes prises dans WhatsApp s'enregistrent toutes seules. Chaque commande confirmée compte 1 commande de votre <RouterLink to="/gestion/solde" class="font-semibold text-emerald-700 underline">solde</RouterLink>.</p>
    <Alert v-if="error">{{ error }}</Alert>

    <div class="grid gap-4 lg:grid-cols-2">
      <!-- Récap : écrit par le marchand depuis son téléphone -->
      <section class="card space-y-3 p-4 sm:p-5">
        <div class="flex items-start gap-3">
          <ColorIcon name="livre" :size="44" />
          <div class="min-w-0 flex-1">
            <h2 class="text-[17px] font-extrabold">Récap</h2>
            <p class="text-sm text-slate-600">Vous discutez depuis votre téléphone, puis vous écrivez « récap » : la commande part au client avec « Je confirme ».</p>
          </div>
          <ToggleSwitch :model-value="data.recap.enabled" :disabled="busy === 'recap'" label="Récap allumé" @update:model-value="save('recap', '/whatsapp/recap', { enabled: $event })" />
        </div>
        <div class="grid grid-cols-3 gap-2 text-xs text-slate-600">
          <span class="rounded-xl bg-slate-50 p-2.5"><b class="block text-base text-slate-900">{{ data.recap.remind_hours }} h</b>relance du client</span>
          <span class="rounded-xl bg-slate-50 p-2.5"><b class="block text-base text-slate-900">{{ data.recap.expire_hours }} h</b>annulation, stock rendu</span>
          <span class="rounded-xl p-2.5" :class="data.recap.waiting ? 'bg-ocre-50 text-ocre-800' : 'bg-slate-50'"><b class="block text-base" :class="data.recap.waiting ? 'text-ocre-800' : 'text-slate-900'">{{ data.recap.waiting }}</b>sans réponse</span>
        </div>
        <details class="text-sm">
          <summary class="cursor-pointer font-semibold text-emerald-700">Comment ça marche</summary>
          <ul class="mt-2 list-disc space-y-1 pl-5 text-slate-600">
            <li>« récap » seul : l'application relit la conversation. « récap 2 robes M Angré » : vos précisions comptent d'abord.</li>
            <li>S'il manque la taille ou la commune, le client la choisit dans une liste.</li>
            <li>Le client touche « Je confirme », ou vous écrivez « confirmé » dans la discussion.</li>
            <li>Il faut le numéro officiel resté dans l'application WhatsApp Business (coexistence).</li>
          </ul>
        </details>
      </section>

      <!-- Modèles : le client écrit « modèles » -->
      <section v-if="data.models" class="card space-y-3 p-4 sm:p-5">
        <div class="flex items-start gap-3">
          <ColorIcon name="produit" :size="44" />
          <div class="min-w-0 flex-1">
            <h2 class="text-[17px] font-extrabold">Modèles</h2>
            <p class="text-sm text-slate-600">Le client écrit « modèles » : il voit vos catégories, puis les photos, ajoute un ou plusieurs articles en touchant, et termine.</p>
          </div>
          <ToggleSwitch :model-value="data.models.enabled" :disabled="busy === 'models'" label="Modèles allumés" @update:model-value="save('models', '/whatsapp/models', { enabled: $event })" />
        </div>
        <p class="rounded-xl bg-slate-50 p-2.5 text-sm text-slate-600"><b class="text-slate-900">{{ data.models.orders_last_30_days }}</b> commande(s) en 30 jours · les catégories viennent de vos collections.</p>
      </section>

      <!-- Commandes avec codes sans live (avant dans le menu Live) -->
      <section class="card flex flex-wrap items-center gap-3 p-4 sm:p-5">
        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-[14px] bg-slate-100 font-display text-sm font-extrabold text-slate-700" aria-hidden="true">A1</span>
        <span class="min-w-0 flex-1">
          <b class="block text-[17px] font-extrabold">Commandes avec codes</b>
          <span class="text-sm text-slate-600">Sans live : vos clients envoient le code A1, A2… et la commande s'enregistre.{{ codes?.status === 'live' ? ' Ouvertes en ce moment.' : '' }}</span>
        </span>
        <RouterLink :to="codes ? `/gestion/whatsapp/commandes-codes/${codes.id}` : '/gestion/whatsapp/commandes-codes'" class="btn-brand">{{ codes?.status === 'live' ? 'Voir les commandes' : 'Ouvrir les commandes' }}</RouterLink>
      </section>
    </div>

    <WhatsAppCatalog v-if="data.catalog" :data="data" @update="data = $event" />

    <!-- Dernières commandes WhatsApp (récap, catalogue, modèles) -->
    <section id="commandes" class="card p-4 sm:p-5">
      <h2 class="text-[17px] font-extrabold">Commandes WhatsApp récentes</h2>
      <ul class="mt-2 divide-y divide-slate-100">
        <li v-for="o in data.orders" :key="o.id">
          <RouterLink :to="`/gestion/commandes/${o.id}`" class="flex items-center gap-3 py-2.5">
            <span class="min-w-0 flex-1">
              <b class="block truncate text-[15px]">{{ o.customer ?? o.reference }}</b>
              <span class="text-xs text-slate-500">{{ o.reference }} · {{ ORIGINS[o.channel] ?? o.channel }} · {{ money(o.total) }}</span>
            </span>
            <StateBadge :status="o.status" :label="o.status_label" class="shrink-0" />
            <Icon name="chevron" :size="16" class="shrink-0 text-slate-400" />
          </RouterLink>
        </li>
        <li v-if="!data.orders.length" class="py-6 text-center text-sm text-slate-500">Pas encore de commande prise dans WhatsApp.</li>
      </ul>
    </section>
  </div>
  <p v-else-if="!error" class="text-sm text-slate-500">Chargement…</p>
  <Alert v-else>{{ error }}</Alert>
</template>
