<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Billing from './Billing.vue'
import Solde from './Solde.vue'
import Materiel from './Materiel.vue'
import { credits, loadCredits, openRecharge } from '../../lib/credits'
import { offerState } from '../../lib/offre'
import { useAuthStore } from '../../stores/auth'

/**
 * Mon offre Jibiat : tout ce que le marchand paie à Jibiat Marchand au même endroit. En haut, l'offre et le solde ;
 * puis ce qui est payant ; puis trois onglets qui reprennent les écrans existants : Offre (abonnement, clé d'accès,
 * factures), Solde (commandes et IA, recharges), Matériel. Les anciennes adresses (/abonnement, /solde, /materiel)
 * mènent ici, sur le bon onglet, avec leurs paramètres (retour de paiement, clé d'accès).
 */
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const TABS = computed(() => [
  ...(auth.can('billing.manage') ? [{ key: 'offre', label: 'Offre' }] : []),
  { key: 'solde', label: 'Solde' },
  ...(auth.can('billing.manage') && auth.profile?.materiel ? [{ key: 'materiel', label: 'Matériel' }] : []),
])
const valid = (key) => TABS.value.some((t) => t.key === key)
const tab = ref(valid(route.query.onglet) ? route.query.onglet : TABS.value[0].key)
// Un lien vers un autre onglet alors que la page est ouverte (compteur, carte de Plus)
watch(() => route.query.onglet, (key) => {
  if (valid(key)) tab.value = key
})
function pick(key) {
  tab.value = key
  router.replace({ query: { ...route.query, onglet: key } })
}

const state = computed(() => offerState(auth.store))
onMounted(() => credits.data || loadCredits())

function changePlan() {
  pick('offre')
  // Les offres sont plus bas, dans l'onglet
  setTimeout(() => document.getElementById('choisir-offre')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50)
}
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-4">
    <!-- L'offre et le solde, en un coup d'œil -->
    <section class="rounded-[22px] bg-brand-grad p-4 text-white">
      <div class="grid grid-cols-2 gap-3">
        <div class="min-w-0">
          <p class="text-xs font-semibold opacity-90">Mon abonnement</p>
          <p class="font-display text-2xl font-extrabold leading-tight">{{ state.plan }}</p>
          <p class="text-xs opacity-90">{{ state.detail }}</p>
        </div>
        <div class="min-w-0 border-l border-white/30 pl-3">
          <p class="text-xs font-semibold opacity-90">Mon solde</p>
          <template v-if="state.orders !== null">
            <p class="font-display text-2xl font-extrabold leading-tight tabular-nums"><span aria-hidden="true">🧾 </span>{{ state.orders }}<span class="sr-only"> commandes</span></p>
            <p class="text-xs opacity-90"><span aria-hidden="true">✨ </span>{{ state.ai }} actions IA</p>
          </template>
          <template v-else>
            <p class="font-display text-2xl font-extrabold leading-tight">Gratuit</p>
            <p class="text-xs opacity-90">Rien n'est compté pour l'instant</p>
          </template>
        </div>
      </div>
      <div class="mt-3 grid grid-cols-2 gap-2">
        <button v-if="auth.can('billing.manage')" type="button" class="rounded-xl bg-white py-2.5 text-sm font-extrabold text-emerald-800" @click="changePlan">Changer d'offre</button>
        <button v-if="state.orders !== null" type="button" class="rounded-xl bg-white/20 py-2.5 text-sm font-extrabold text-white" :class="auth.can('billing.manage') ? '' : 'col-span-2'" @click="openRecharge()">Recharger</button>
      </div>
    </section>

    <!-- Ce qui est payant, et ce qui ne l'est pas -->
    <details class="card px-4">
      <summary class="flex cursor-pointer items-center justify-between py-3 text-sm font-extrabold">Qu'est-ce qui est payant ?<span class="text-slate-400" aria-hidden="true">›</span></summary>
      <ul class="space-y-2 pb-4 text-sm text-slate-700">
        <li><b class="text-emerald-800">Gratuit :</b> la boutique en ligne et les commandes saisies à la main.</li>
        <li><b class="text-sky-800">L'abonnement</b> débloque des fonctions : nom de domaine, WhatsApp automatique, équipe…</li>
        <li><b class="text-ocre-800">Le solde</b> se consomme : 1 commande prise en live, par WhatsApp ou venue d'un boost = 1 en moins. Les actions de l'assistant IA aussi.</li>
        <li v-if="auth.profile?.materiel"><b class="text-violet-800">Le matériel</b> s'achète une fois : étiquettes, imprimantes, équipement de live.</li>
      </ul>
    </details>

    <div v-if="TABS.length > 1" class="flex gap-2" role="tablist" aria-label="Mon offre Jibiat">
      <button v-for="t in TABS" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key"
              class="flex-1 rounded-full py-2 text-sm font-extrabold transition sm:flex-none sm:px-5"
              :class="tab === t.key ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 shadow-soft'" @click="pick(t.key)">
        {{ t.label }}
        <span v-if="t.key === 'solde' && state.soldeLevel" class="ml-1 inline-block h-2 w-2 rounded-full align-middle" :class="state.soldeLevel === 'red' ? 'bg-red-600' : 'bg-ocre-600'" aria-hidden="true" />
      </button>
    </div>

    <Billing v-if="tab === 'offre'" embedded />
    <Solde v-else-if="tab === 'solde'" />
    <Materiel v-else-if="tab === 'materiel'" />
  </div>
</template>
