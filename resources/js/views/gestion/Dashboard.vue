<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import Alert from '../../components/Alert.vue'
import { daysUntil, formatDate } from '../../lib/format'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const route = useRoute()

const welcome = computed(() => route.query.bienvenue === '1')
const subscription = computed(() => auth.store?.subscription)
// L'abonnement ne concerne que le propriétaire
const trialDays = computed(() =>
  auth.can('billing.manage') && subscription.value?.status === 'trialing' ? daysUntil(subscription.value.trial_ends_at) : null,
)

// Premiers pas : ce qui existe déjà, puis ce qui arrive avec les prochaines phases
const steps = computed(() => [
  ...(auth.can('store.manage')
    ? [{ title: 'Compléter les informations de la boutique', text: 'Slogan, numéro WhatsApp des clients, adresse.', to: '/gestion/parametres', done: !!auth.store?.tagline }]
    : []),
  ...(auth.can('team.manage')
    ? [{ title: 'Inviter votre équipe', text: 'Gérant, agent de confirmation, préparateur, caissier.', to: '/gestion/equipe' }]
    : []),
  ...(auth.can('products.manage')
    ? [{ title: 'Ajouter vos produits et leurs variantes', text: 'Tailles, couleurs, photos, stock. Ou importez un fichier Excel.', to: '/gestion/catalogue/nouveau' }]
    : []),
  { title: 'Ouvrir votre boutique en ligne', text: 'Thème, livraison, paiement à la livraison.', soon: true },
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
      <p class="mt-1 text-slate-600">
        {{ auth.profile?.is_owner ? 'Voici les premiers pas pour lancer votre boutique.' : `Bienvenue dans l'équipe de ${auth.store?.name}.` }}
      </p>
    </section>

    <ol class="space-y-3">
      <li v-for="(step, index) in steps" :key="step.title" class="card flex items-start gap-4 p-4">
        <span
          class="grid h-8 w-8 shrink-0 place-items-center rounded-full text-sm font-semibold"
          :class="step.done ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'"
        >
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
  </div>
</template>
