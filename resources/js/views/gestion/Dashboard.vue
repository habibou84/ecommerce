<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import Alert from '../../components/Alert.vue'
import http from '../../lib/http'
import { daysUntil, formatDate, money } from '../../lib/format'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const route = useRoute()

const stats = ref(null)
onMounted(async () => {
  stats.value = (await http.get('/dashboard')).data.data
})

const welcome = computed(() => route.query.bienvenue === '1')
const subscription = computed(() => auth.store?.subscription)
// L'abonnement ne concerne que le propriétaire
const trialDays = computed(() =>
  auth.can('billing.manage') && subscription.value?.status === 'trialing' ? daysUntil(subscription.value.trial_ends_at) : null,
)

// Premiers pas, selon les droits de la personne
const steps = computed(() => [
  ...(auth.can('store.manage')
    ? [
        { title: 'Compléter les informations de la boutique', text: 'Slogan, numéro WhatsApp des clients, adresse.', to: '/gestion/parametres', done: !!auth.store?.tagline },
        { title: 'Vérifier vos frais de livraison', text: 'Une zone « Abidjan » à 1 500 F est créée par défaut : adaptez-la par quartier.', to: '/gestion/zones-de-livraison' },
      ]
    : []),
  ...(auth.can('products.manage')
    ? [{ title: 'Ajouter vos produits et leurs variantes', text: 'Tailles, couleurs, photos, stock. Ou importez un fichier Excel.', to: '/gestion/catalogue/nouveau' }]
    : []),
  ...(auth.can('orders.manage')
    ? [{ title: 'Saisir votre première commande', text: 'Collez le message WhatsApp du client : tout se remplit.', to: '/gestion/commandes/nouvelle' }]
    : []),
  ...(auth.can('team.manage') ? [{ title: 'Inviter votre équipe', text: 'Gérant, agent de confirmation, préparateur, caissier.', to: '/gestion/equipe' }] : []),
  ...(auth.can('store.manage')
    ? [{ title: 'Ouvrir votre boutique en ligne', text: 'Choisissez un thème, ajoutez votre logo et ouvrez : vos clients commandent et paient à la livraison.', to: '/gestion/boutique-en-ligne', done: !!auth.store?.storefront_enabled }]
    : []),
])
</script>

<template>
  <div class="space-y-6">
    <Alert v-if="welcome" tone="success">
      🎉 Votre boutique <strong>{{ auth.store?.name }}</strong> est créée. Elle est à l'adresse
      <a :href="auth.store?.url" target="_blank" class="font-medium underline">{{ auth.store?.url.replace(/^https?:\/\//, '').replace(/\/$/, '') }}</a>.
    </Alert>

    <Alert v-if="trialDays !== null" tone="info">
      Essai gratuit de l'offre {{ subscription.plan }} : encore <strong>{{ trialDays }} jour{{ trialDays > 1 ? 's' : '' }}</strong>
      (jusqu'au {{ formatDate(subscription.trial_ends_at) }}).
    </Alert>

    <section>
      <h2 class="text-xl font-semibold">Bonjour {{ auth.user?.name?.split(' ')[0] }} 👋</h2>
    </section>

    <section v-if="stats?.orders" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <RouterLink to="/gestion/commandes" class="card p-4 hover:border-emerald-600">
        <p class="text-xs text-slate-500">Commandes du jour</p>
        <p class="text-2xl font-semibold">{{ stats.orders.today_count }}</p>
        <p class="text-sm text-slate-500">{{ money(stats.orders.today_amount) }}</p>
      </RouterLink>
      <RouterLink :to="auth.can('orders.confirm') ? '/gestion/confirmation' : '/gestion/commandes?status=pending_confirmation'" class="card p-4 hover:border-amber-400">
        <p class="text-xs text-slate-500">À confirmer</p>
        <p class="text-2xl font-semibold" :class="stats.orders.to_confirm ? 'text-amber-700' : ''">{{ stats.orders.to_confirm }}</p>
        <p v-if="stats.orders.callbacks_due" class="text-sm text-amber-800">{{ stats.orders.callbacks_due }} rappel(s) à faire</p>
      </RouterLink>
      <RouterLink to="/gestion/commandes?status=to_ship" class="card p-4 hover:border-emerald-600">
        <p class="text-xs text-slate-500">À expédier</p>
        <p class="text-2xl font-semibold">{{ stats.orders.to_ship }}</p>
      </RouterLink>
      <div class="card p-4">
        <p class="text-xs text-slate-500">Livré ce mois-ci</p>
        <p class="text-2xl font-semibold">{{ money(stats.orders.month_revenue) }}</p>
        <p class="text-sm text-slate-500">{{ stats.orders.month_delivered }} commande(s)</p>
      </div>
    </section>

    <RouterLink v-if="stats?.stock && (stats.stock.low || stats.stock.out)" to="/gestion/stock" class="block">
      <Alert tone="warning">
        Stock : {{ stats.stock.out }} article(s) épuisé(s), {{ stats.stock.low }} en stock bas. Voir le stock →
      </Alert>
    </RouterLink>

    <section>
      <h3 class="mb-3 font-semibold">Pour bien démarrer</h3>
      <ol class="space-y-3">
        <li v-for="(step, index) in steps" :key="step.title" class="card flex items-start gap-4 p-4">
          <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full text-sm font-semibold" :class="step.done ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'">
            {{ step.done ? '✓' : index + 1 }}
          </span>
          <div class="min-w-0 flex-1">
            <p class="font-medium" :class="{ 'text-slate-400': step.soon }">{{ step.title }}</p>
            <p class="text-sm text-slate-500">{{ step.text }}</p>
          </div>
          <RouterLink v-if="step.to" :to="step.to" class="btn-secondary shrink-0 px-3 py-1.5">{{ step.done ? 'Modifier' : 'Commencer' }}</RouterLink>
          <span v-else-if="step.soon" class="shrink-0 rounded bg-slate-100 px-2 py-1 text-xs text-slate-500">Bientôt</span>
        </li>
      </ol>
    </section>
  </div>
</template>
