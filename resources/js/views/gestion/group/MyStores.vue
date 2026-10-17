<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import GroupTabs from './GroupTabs.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { boot } from '../../../lib/boot'
import { money, slugify } from '../../../lib/format'
import { useAuthStore } from '../../../stores/auth'

const data = ref(null)
const error = ref('')
const busy = ref(false)
const auth = useAuthStore()
const form = reactive({ name: '', slug: '', path: '' })
const errors = ref({})
const slugTouched = ref(false)

watch(() => form.name, (name) => {
  if (!slugTouched.value) {
    form.slug = slugify(name)
    form.path = slugify(name).slice(0, 30).replace(/-+$/, '')
  }
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

// Boutique de cette adresse : on la choisit sans changer d'adresse ; sinon lien de connexion vers son adresse
const sameAddress = (store) => (auth.profile?.stores ?? []).some((s) => s.id === store.id)
async function open(store) {
  error.value = ''
  if (sameAddress(store)) {
    auth.switchTo(store.id)
    return
  }
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
    const next = data.value.next_address
    const { data: res } = await http.post('/my-stores', next ? { name: form.name, path: form.path } : { name: form.name, slug: form.slug })
    if (next) auth.switchTo(res.store_id, '/gestion?bienvenue=1')
    else window.location.assign(res.redirect)
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
    busy.value = false
  }
}

// À cette adresse : la boutique principale et ses boutiques hébergées (sans abonnement commun : celle-ci)
const here = computed(() => (data.value?.stores ?? []).filter((s) => (group.value ? s.is_main || s.is_hosted : s.is_current)))
// Boutiques du propriétaire qui ont encore leur propre adresse
const elsewhere = computed(() => (data.value?.stores ?? []).filter((s) => !here.value.includes(s)))
const address = computed(() => (group.value?.address ?? '').replace(/^https?:\/\//, ''))

async function join(store) {
  if (!window.confirm(`Réunir ${store.name} à l'adresse ${address.value} ? Ses produits, commandes et équipe ne changent pas ; son ancienne adresse redirigera vers sa nouvelle page. Son propre abonnement ne sera plus renouvelé : elle est couverte par votre offre ${group.value.plan}.`)) return
  error.value = ''
  try {
    data.value = (await http.post(`/my-stores/${store.id}/attach`)).data.data
    await auth.fetch()
  } catch (e) {
    error.value = errorMessage(e)
  }
}

async function joinAll() {
  if (!window.confirm(`Réunir ces ${elsewhere.value.length} boutiques à l'adresse ${address.value} ? Leurs produits, commandes et équipes ne changent pas ; leurs anciennes adresses redirigeront vers leurs nouvelles pages.`)) return
  busy.value = true
  error.value = ''
  try {
    for (const store of elsewhere.value) {
      data.value = (await http.post(`/my-stores/${store.id}/attach`)).data.data
    }
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
    await auth.fetch()
  }
}

async function separate(store) {
  if (!window.confirm(`Séparer ${store.name} ? Elle retrouve sa propre adresse et sa propre offre (gratuite sans abonnement).`)) return
  error.value = ''
  try {
    data.value = (await http.delete(`/my-stores/${store.id}/attach`)).data.data
    await auth.fetch()
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
      <h2 class="px-4 pb-2 pt-4 font-semibold">{{ group ? `À l'adresse ${address}` : 'Cette boutique' }}</h2>
      <div v-for="s in here" :key="s.id" class="flex flex-wrap items-center gap-3 p-4">
        <div class="min-w-0 flex-1">
          <p class="font-medium">{{ s.name }}
            <span v-if="s.is_current" class="ml-1 rounded bg-emerald-50 px-1.5 text-xs text-emerald-800">Ouverte</span>
            <span v-if="s.is_main" class="ml-1 rounded bg-sky-50 px-1.5 text-xs text-sky-800">Principale</span>
          </p>
          <p class="truncate text-xs text-slate-500">{{ s.url.replace(/^https?:\/\//, '').replace(/\/$/, '') }} · Offre {{ s.plan }}<template v-if="!s.is_open"> · {{ s.status_label }}</template></p>
          <p class="mt-1 text-sm text-slate-600">{{ s.today_count }} commande{{ s.today_count > 1 ? 's' : '' }} aujourd'hui · {{ s.to_confirm }} à confirmer · {{ money(s.month_revenue) }} livrés ce mois-ci</p>
        </div>
        <div class="flex gap-2">
          <button v-if="s.is_hosted" class="btn-ghost px-2 text-sm" @click="separate(s)">Séparer</button>
          <button v-if="!s.is_current && s.is_open" class="btn-secondary px-3 text-sm" @click="open(s)">Ouvrir</button>
        </div>
      </div>
    </section>

    <section v-if="elsewhere.length" class="card space-y-3 p-4">
      <div>
        <h2 class="font-semibold">Ces boutiques ont encore leur propre adresse</h2>
        <p v-if="group" class="text-sm text-slate-600">
          Réunissez-les à l'adresse {{ address }} : vous passerez de l'une à l'autre avec le menu en haut, sans changer de lien.
          Leurs produits, commandes et équipes ne changent pas, et leurs anciennes adresses redirigent vers leurs nouvelles pages.
        </p>
        <p v-else class="text-sm text-slate-600">
          Pour les réunir à une seule adresse et passer de l'une à l'autre sans changer de lien, votre boutique principale doit avoir l'offre <strong>Business</strong>.
          <RouterLink to="/gestion/abonnement" class="text-emerald-700 underline">Voir les offres</RouterLink>
        </p>
      </div>
      <ul class="divide-y divide-slate-100">
        <li v-for="s in elsewhere" :key="s.id" class="flex flex-wrap items-center gap-3 py-2">
          <span class="min-w-0 flex-1"><span class="block font-medium">{{ s.name }}</span><span class="block truncate text-xs text-slate-500">{{ s.url.replace(/^https?:\/\//, '').replace(/\/$/, '') }}</span></span>
          <button v-if="group" class="btn-secondary px-2 text-sm" :disabled="busy || !room" @click="join(s)">Réunir à cette adresse</button>
          <button v-if="s.is_open" class="btn-ghost px-2 text-sm" @click="open(s)">Ouvrir</button>
        </li>
      </ul>
      <button v-if="group && elsewhere.length > 1" class="btn-brand" :disabled="busy || !room" @click="joinAll">{{ busy ? 'Réunion en cours…' : 'Tout réunir ici' }}</button>
      <p v-if="group && !room" class="text-xs text-amber-800">Votre offre couvre {{ group.limit }} boutiques au plus : séparez une boutique pour en réunir une autre.</p>
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
          <template v-if="data.next_address">Elle reste à la même adresse : vous passez de l'une à l'autre avec le menu en haut de l'écran, et ses clients la trouvent sur sa page. Elle est couverte par votre abonnement {{ group.plan }}.</template>
          <template v-else>Elle aura sa propre adresse, commence par l'essai gratuit puis a sa propre offre.</template>
          Produits, commandes et équipe sont séparés d'une boutique à l'autre.
        </p>
      </div>
      <Field id="new-name" label="Nom de la boutique" :error="errors.name">
        <input id="new-name" v-model="form.name" class="input" maxlength="80" required />
      </Field>
      <Field v-if="data.next_address" id="new-path" label="Adresse de sa page" :error="errors.path">
        <div class="flex items-stretch overflow-hidden rounded-lg border border-slate-300 focus-within:border-emerald-600">
          <span class="flex max-w-[60%] items-center truncate bg-slate-50 px-3 text-sm text-slate-500">{{ data.next_address.replace(/^https?:\/\//, '') }}/</span>
          <input id="new-path" v-model="form.path" class="min-w-0 flex-1 px-3 py-2.5 text-base outline-none sm:text-sm" maxlength="30" autocapitalize="off" spellcheck="false" required @input="slugTouched = true" />
        </div>
      </Field>
      <Field v-else id="new-slug" label="Adresse" :error="errors.slug">
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
