<script setup>
import { computed, onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import ColorIcon from '../../../components/ColorIcon.vue'
import Icon from '../../../components/Icon.vue'
import StateBadge from '../../../components/StateBadge.vue'
import ToggleSwitch from '../../../components/ToggleSwitch.vue'
import WhatsAppCatalog from '../../../components/WhatsAppCatalog.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'

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
// Frais de livraison envoyés par le récap et les assistants : « Ils sont justes » quand la boutique les a relus
async function ratesChecked() {
  busy.value = 'rates'
  error.value = ''
  try {
    await http.post('/delivery-rates/checked')
    data.value.delivery.checked = true
    data.value.todo.rates = false
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = ''
  }
}
const ratesLine = computed(() => {
  const d = data.value?.delivery
  if (!d || d.base_fee == null) return 'Aucun prix de livraison enregistré.'
  return `${d.city ?? 'Votre ville'} : ${money(d.base_fee)}${d.specific ? `, et ${d.specific} prix à part` : ' partout'}`
})
const codes = computed(() => data.value?.codes)
</script>

<template>
  <div v-if="data" class="space-y-4 pb-24 sm:pb-0">
    <div>
      <p class="explain">Les commandes prises dans WhatsApp s'enregistrent seules.</p>
      <p class="explain-detail">Chaque commande confirmée compte 1 commande de votre <RouterLink to="/gestion/mon-offre?onglet=solde" class="font-semibold text-emerald-700 underline">solde</RouterLink>.</p>
    </div>
    <Alert v-if="error">{{ error }}</Alert>

    <!-- Frais de livraison : le récap et les assistants les envoient aux clients tels qu'ils sont enregistrés -->
    <section v-if="data.delivery && !data.delivery.checked" class="flex flex-col gap-3 rounded-2xl border border-ocre-200 bg-ocre-50 p-4 sm:flex-row sm:items-center">
      <ColorIcon name="moto" :size="44" />
      <div class="min-w-0 flex-1">
        <p class="explain">Vérifiez vos frais de livraison.</p>
        <p class="explain-detail">Le récap et les assistants envoient ces prix à vos clients. Aujourd'hui : {{ ratesLine }}.</p>
      </div>
      <div class="flex shrink-0 flex-wrap gap-2">
        <RouterLink to="/gestion/livraisons/tarifs" class="btn-brand px-3 py-1.5 text-sm">Vérifier mes tarifs</RouterLink>
        <button type="button" class="btn-secondary px-3 py-1.5 text-sm" :disabled="busy === 'rates'" @click="ratesChecked">Ils sont justes</button>
      </div>
    </section>
    <p v-else-if="data.delivery" class="flex flex-wrap items-center gap-x-2 text-sm text-slate-700">
      <Icon name="truck" :size="16" class="text-emerald-700" />Frais envoyés aux clients : {{ ratesLine }}.
      <RouterLink to="/gestion/livraisons/tarifs" class="font-semibold text-emerald-700 underline">Modifier</RouterLink>
    </p>

    <div class="grid gap-4 lg:grid-cols-2">
      <!-- Récap : le marchand écrit « récap » depuis son téléphone quand le client a donné sa commande -->
      <section class="card space-y-4 p-4 sm:p-5 lg:col-span-2">
        <div class="flex items-start gap-3">
          <ColorIcon name="livre" :size="44" />
          <div class="min-w-0 flex-1">
            <h2 class="text-lg font-extrabold">Récap</h2>
            <p class="text-sm text-slate-600">La commande s'enregistre toute seule à partir de votre conversation WhatsApp.</p>
          </div>
          <ToggleSwitch :model-value="data.recap.enabled" :disabled="busy === 'recap'" label="Récap allumé" @update:model-value="save('recap', '/whatsapp/recap', { enabled: $event })" />
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
          <!-- Les trois étapes -->
          <ol class="space-y-3">
            <li class="flex gap-3">
              <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-slate-900 text-sm font-black text-white" aria-hidden="true">1</span>
              <span class="min-w-0 text-sm text-slate-700"><b class="block text-base text-slate-900">Votre client vous écrit sur WhatsApp</b>Vous discutez normalement, depuis votre téléphone.</span>
            </li>
            <li class="flex gap-3">
              <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-slate-900 text-sm font-black text-white" aria-hidden="true">2</span>
              <span class="min-w-0 text-sm text-slate-700">
                <b class="block text-base text-slate-900">Dès qu'il vous a dit ce qu'il commande, écrivez « récap »</b>
                C'est-à-dire l'article, la taille ou la couleur, la quantité, et si possible sa commune et son quartier.
                L'application relit la conversation et prépare la commande. S'il manque la taille ou la commune, elle les demande au client.
              </span>
            </li>
            <li class="flex gap-3">
              <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-slate-900 text-sm font-black text-white" aria-hidden="true">3</span>
              <span class="min-w-0 text-sm text-slate-700"><b class="block text-base text-slate-900">Le client reçoit le récapitulatif</b>Articles, prix, frais de livraison, total, et un bouton « Je confirme ». Il touche le bouton : la commande est enregistrée comme confirmée.</span>
            </li>
          </ol>

          <!-- Exemple, avec le bon moment marqué -->
          <figure class="space-y-1.5 rounded-2xl bg-slate-50 p-3 text-sm" aria-label="Exemple de conversation">
            <figcaption class="text-xs font-black uppercase tracking-wide text-slate-500">Exemple</figcaption>
            <p class="w-fit max-w-[85%] rounded-xl rounded-tl-sm bg-white px-3 py-1.5 shadow-soft"><b>Client :</b> Bonsoir, la robe wax rouge vous avez en M ?</p>
            <p class="ml-auto w-fit max-w-[85%] rounded-xl rounded-tr-sm bg-emerald-100 px-3 py-1.5"><b>Vous :</b> Oui ma chérie, 15 000 F.</p>
            <p class="w-fit max-w-[85%] rounded-xl rounded-tl-sm bg-white px-3 py-1.5 shadow-soft"><b>Client :</b> Ok, je prends une. Je suis à Cocody Angré.</p>
            <p class="flex items-start gap-1.5 px-1 text-sm font-semibold text-emerald-800"><Icon name="check" :size="15" class="mt-0.5 shrink-0" />Le client a donné l'article, la taille, la quantité et le lieu : c'est le moment.</p>
            <p class="ml-auto w-fit rounded-xl rounded-tr-sm bg-emerald-100 px-3 py-1.5 font-bold"><b>Vous :</b> récap</p>
            <div class="w-fit max-w-[90%] rounded-xl rounded-tl-sm bg-white px-3 py-2 shadow-soft">
              <p><b>Le client reçoit :</b> Robe wax rouge, taille M · Livraison Cocody Angré : 1 500 F · <b>Total : 16 500 F</b></p>
              <span class="mt-1.5 inline-block rounded-lg border border-emerald-200 px-3 py-1 text-sm font-bold text-emerald-800">Je confirme</span>
            </div>
          </figure>
        </div>

        <!-- Ce qui se passe sans réponse, et qui n'a pas encore confirmé -->
        <ul class="space-y-1 text-sm text-slate-700">
          <li><b>Pas de réponse au bout de {{ data.recap.remind_hours }} h ?</b> On relance le client.</li>
          <li><b>Toujours rien au bout de {{ data.recap.expire_hours }} h ?</b> La commande est annulée et l'article remis en stock. Rien n'est compté.</li>
        </ul>
        <RouterLink v-if="data.recap.waiting" to="/gestion/messages?filtre=a-confirmer" class="flex items-center gap-2 rounded-xl bg-ocre-50 p-3 text-sm font-semibold text-ocre-800">
          <Icon name="clock" :size="16" class="shrink-0" />
          <span class="min-w-0 flex-1">{{ data.recap.waiting }} {{ data.recap.waiting > 1 ? "clients n'ont pas encore confirmé" : "client n'a pas encore confirmé" }}</span>
          <span class="font-black">Voir ▸</span>
        </RouterLink>

        <!-- Ce qu'il faut pour que ça marche, avec le témoin -->
        <div class="rounded-xl border border-slate-200 p-3 text-sm">
          <p class="text-slate-700"><b class="text-slate-900">Pour que ça marche :</b> votre numéro WhatsApp officiel doit rester dans l'application WhatsApp Business de votre téléphone.</p>
          <p v-if="data.phone_message_at" class="mt-1.5 flex items-center gap-1.5 font-semibold text-emerald-800"><Icon name="check" :size="15" class="shrink-0" />Ça marche : message reçu de votre téléphone le {{ formatDateTime(data.phone_message_at) }}</p>
          <p v-else class="mt-1.5 font-semibold text-ocre-800">Pour vérifier, écrivez un message à un client depuis votre téléphone : il doit apparaître dans Conversations.</p>
        </div>

        <details class="text-sm">
          <summary class="cursor-pointer font-semibold text-emerald-700">Plus de détails</summary>
          <ul class="mt-2 list-disc space-y-1.5 pl-5 text-slate-600">
            <li><b>Depuis l'application :</b> dans Conversations, touchez « Envoyer le récap » (le client ne voit pas le mot), puis « Confirmer la commande » s'il dit oui par écrit. Écrire « récap » ou « confirmé » marche aussi.</li>
            <li><b>Le client commande plusieurs articles :</b> écrivez « récap » une fois qu'il a tout donné. S'il en ajoute un après, écrivez « récap » à nouveau : la même commande est mise à jour.</li>
            <li><b>La conversation est confuse</b> (plusieurs articles discutés, changement d'avis) : écrivez ce qu'il a vraiment choisi après le mot, par exemple « récap 2 robes wax M Angré ». Ce que vous écrivez passe avant le reste.</li>
            <li><b>Le client hésite encore entre plusieurs articles :</b> attendez qu'il ait choisi. Sans article reconnu, rien n'est envoyé au client et vous êtes prévenu.</li>
            <li><b>Le client répond « oui » par écrit au lieu de toucher le bouton :</b> écrivez « confirmé » dans la discussion, la commande est confirmée.</li>
            <li>Pas besoin de demander son nom ni son numéro : WhatsApp les donne déjà.</li>
            <li>Chaque commande confirmée compte 1 commande de votre solde.</li>
          </ul>
        </details>
      </section>

      <!-- Modèles : le client écrit « modèles » -->
      <section v-if="data.models" class="card space-y-3 p-4 sm:p-5">
        <div class="flex items-start gap-3">
          <ColorIcon name="produit" :size="44" />
          <div class="min-w-0 flex-1">
            <h2 class="text-lg font-extrabold">Modèles</h2>
            <p class="explain-detail">Le client écrit « modèles » : il voit vos catégories et les photos, et ajoute ses articles d'une touche.</p>
          </div>
          <ToggleSwitch :model-value="data.models.enabled" :disabled="busy === 'models'" label="Modèles allumés" @update:model-value="save('models', '/whatsapp/models', { enabled: $event })" />
        </div>
        <p class="rounded-xl bg-slate-50 p-2.5 text-sm text-slate-600"><b class="text-slate-900">{{ data.models.orders_last_30_days }}</b> commande(s) en 30 jours · les catégories viennent de vos collections.</p>
      </section>

      <!-- Commandes avec codes sans live (avant dans le menu Live) : seulement si la console les allume -->
      <section v-if="codes" class="card flex flex-wrap items-center gap-3 p-4 sm:p-5">
        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-[14px] bg-slate-100 font-display text-sm font-extrabold text-slate-700" aria-hidden="true">A1</span>
        <span class="min-w-0 flex-1">
          <b class="block text-lg font-extrabold">Commandes avec codes</b>
          <span class="text-sm text-slate-600">Sans live : vos clients envoient le code A1, A2… et la commande s'enregistre.{{ codes?.status === 'live' ? ' Ouvertes en ce moment.' : '' }}</span>
        </span>
        <RouterLink :to="codes.id ? `/gestion/whatsapp/commandes-codes/${codes.id}` : '/gestion/whatsapp/commandes-codes'" class="btn-brand">{{ codes?.status === 'live' ? 'Voir les commandes' : 'Ouvrir les commandes' }}</RouterLink>
      </section>
    </div>

    <WhatsAppCatalog v-if="data.catalog" :data="data" @update="data = $event" />

    <!-- Dernières commandes WhatsApp (récap, catalogue, modèles) -->
    <section id="commandes" class="card p-4 sm:p-5">
      <h2 class="text-lg font-extrabold">Commandes WhatsApp récentes</h2>
      <ul class="mt-2 divide-y divide-slate-100">
        <li v-for="o in data.orders" :key="o.id">
          <RouterLink :to="`/gestion/commandes/${o.id}`" class="flex items-center gap-3 py-2.5">
            <span class="min-w-0 flex-1">
              <b class="block truncate text-base">{{ o.customer ?? o.reference }}</b>
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
