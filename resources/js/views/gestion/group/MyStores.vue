<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import GroupTabs from './GroupTabs.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { boot } from '../../../lib/boot'
import { money, slugify } from '../../../lib/format'

const data = ref(null)
const error = ref('')
const busy = ref(false)
const form = reactive({ name: '', slug: '' })
const errors = ref({})
const slugTouched = ref(false)

watch(() => form.name, (name) => {
  if (!slugTouched.value) form.slug = slugify(name)
})

async function load() {
  try {
    data.value = (await http.get('/my-stores')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

const group = computed(() => data.value?.group)
const room = computed(() => group.value && (group.value.limit === null || group.value.used < group.value.limit))

async function open(store) {
  error.value = ''
  try {
    window.location.assign((await http.post(`/auth/switch/${store.id}`)).data.redirect)
  } catch (e) {
    error.value = errorMessage(e)
  }
}

async function create() {
  busy.value = true
  error.value = ''
  errors.value = {}
  try {
    window.location.assign((await http.post('/my-stores', form)).data.redirect)
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
    busy.value = false
  }
}

async function attach(store, value) {
  const question = value
    ? `Mettre ${store.name} sous l'abonnement ${group.value.plan} de ${group.value.main_name} ? Son propre abonnement ne sera plus renouvelé.`
    : `Retirer ${store.name} de l'abonnement commun ? Elle repassera à sa propre offre (gratuite sans abonnement).`
  if (!window.confirm(question)) return
  error.value = ''
  try {
    data.value = (await http[value ? 'post' : 'delete'](`/my-stores/${store.id}/attach`)).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <GroupTabs v-if="data?.group" class="mb-5" />
  <div v-if="data" class="space-y-5">
    <Alert v-if="error">{{ error }}</Alert>

    <section v-if="data.stores.length > 1" class="grid grid-cols-2 gap-3 md:grid-cols-4">
      <div class="card p-3"><p class="text-xs text-slate-500">Commandes aujourd'hui</p><p class="text-lg font-semibold">{{ data.totals.today_count }}</p></div>
      <div class="card p-3"><p class="text-xs text-slate-500">Montant aujourd'hui</p><p class="text-lg font-semibold">{{ money(data.totals.today_amount) }}</p></div>
      <div class="card p-3"><p class="text-xs text-slate-500">À confirmer</p><p class="text-lg font-semibold">{{ data.totals.to_confirm }}</p></div>
      <div class="card p-3"><p class="text-xs text-slate-500">Livré ce mois-ci</p><p class="text-lg font-semibold">{{ money(data.totals.month_revenue) }}</p></div>
    </section>

    <section class="card divide-y divide-slate-100">
      <div v-for="s in data.stores" :key="s.id" class="flex flex-wrap items-center gap-3 p-4">
        <div class="min-w-0 flex-1">
          <p class="font-medium">{{ s.name }}
            <span v-if="s.is_current" class="ml-1 rounded bg-emerald-50 px-1.5 text-xs text-emerald-800">Ouverte ici</span>
            <span v-if="s.is_main" class="ml-1 rounded bg-sky-50 px-1.5 text-xs text-sky-800">Abonnement principal</span>
          </p>
          <p class="truncate text-xs text-slate-500">{{ s.url.replace(/^https?:\/\//, '').replace(/\/$/, '') }} · Offre {{ s.plan }}<template v-if="s.covered"> (abonnement commun)</template><template v-if="!s.is_open"> · {{ s.status_label }}</template></p>
          <p class="mt-1 text-sm text-slate-600">{{ s.today_count }} commande{{ s.today_count > 1 ? 's' : '' }} aujourd'hui · {{ s.to_confirm }} à confirmer · {{ money(s.month_revenue) }} livrés ce mois-ci</p>
        </div>
        <div class="flex gap-2">
          <button v-if="group && !s.is_main && (s.covered || s.attached)" class="btn-ghost px-2 text-sm" @click="attach(s, false)">Retirer de l'abonnement</button>
          <button v-else-if="group && !s.is_main && room" class="btn-secondary px-2 text-sm" @click="attach(s, true)">Mettre sous l'abonnement</button>
          <button v-if="!s.is_current && s.is_open" class="btn-secondary px-3 text-sm" @click="open(s)">Ouvrir</button>
        </div>
      </div>
    </section>

    <Alert v-if="group" tone="info">
      Votre offre {{ group.plan }} couvre {{ group.limit === null ? 'toutes vos boutiques' : `${group.limit} boutiques` }} :
      {{ group.used }} {{ group.used > 1 ? 'en profitent' : 'en profite' }} aujourd'hui. Les boutiques sous l'abonnement commun ont toutes les fonctions de l'offre, sans paiement séparé.
    </Alert>
    <Alert v-else tone="info">
      Chaque boutique a son propre abonnement. L'offre <strong>Business</strong> regroupe plusieurs boutiques sous un seul abonnement.
      <RouterLink to="/gestion/abonnement" class="underline">Voir les offres</RouterLink>
    </Alert>

    <form class="card space-y-4 p-4 sm:p-6" @submit.prevent="create">
      <div>
        <h2 class="font-semibold">Créer une autre boutique</h2>
        <p class="text-sm text-slate-600">
          Avec votre compte actuel, sans nouvelle inscription.
          <template v-if="room">Elle sera couverte par votre abonnement {{ group.plan }}.</template>
          <template v-else>Elle commence par l'essai gratuit, puis a sa propre offre.</template>
          Produits, commandes et équipe sont séparés d'une boutique à l'autre.
        </p>
      </div>
      <Field id="new-name" label="Nom de la boutique" :error="errors.name">
        <input id="new-name" v-model="form.name" class="input" maxlength="80" required />
      </Field>
      <Field id="new-slug" label="Adresse" :error="errors.slug">
        <div class="flex items-stretch overflow-hidden rounded-lg border border-slate-300 focus-within:border-emerald-600">
          <input id="new-slug" v-model="form.slug" class="min-w-0 flex-1 px-3 py-2.5 text-base outline-none sm:text-sm" maxlength="40" autocapitalize="off" spellcheck="false" required @input="slugTouched = true" />
          <span class="flex items-center bg-slate-50 px-3 text-sm text-slate-500">.{{ boot.domain }}</span>
        </div>
      </Field>
      <button class="btn-brand" :disabled="busy">{{ busy ? 'Création…' : 'Créer la boutique' }}</button>
    </form>
  </div>
  <p v-else-if="!error" class="text-sm text-slate-500">Chargement…</p>
  <Alert v-else>{{ error }}</Alert>
</template>
