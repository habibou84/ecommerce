<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import OnlineStoreTabs from '../../../components/OnlineStoreTabs.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { formatDateTime } from '../../../lib/format'

const data = ref(null)
const hostname = ref('')
const errors = ref({})
const error = ref('')
const checking = ref(null)

async function load() {
  try {
    data.value = (await http.get('/domains')).data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function add() {
  errors.value = {}
  error.value = ''
  try {
    await http.post('/domains', { hostname: hostname.value })
    hostname.value = ''
    await load()
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  }
}

async function verify(d) {
  checking.value = d.id
  try {
    Object.assign(d, (await http.post(`/domains/${d.id}/verify`)).data.data)
  } finally {
    checking.value = null
  }
}
const primary = async (d) => { data.value = (await http.post(`/domains/${d.id}/primary`)).data }
async function remove(d) {
  if (!window.confirm(`Retirer ${d.hostname} ?`)) return
  await http.delete(`/domains/${d.id}`)
  await load()
}
const copy = (t) => navigator.clipboard?.writeText(t)
</script>

<template>
  <div class="space-y-5">
    <OnlineStoreTabs />
    <p class="text-sm text-slate-600">Votre boutique est toujours à <strong>{{ data?.subdomain?.replace(/^https?:\/\//, '') }}</strong>. Ajoutez votre propre nom de domaine (ex. www.maboutique.ci) : le site y sera servi, avec un certificat de sécurité (https) automatique. La gestion reste sur l'adresse Jibiat Marchand.</p>
    <Alert v-if="data?.hosted_by" tone="info">Cette boutique est une page de {{ data.hosted_by }} : ajoutez votre nom de domaine à {{ data.hosted_by }}, il servira aussi cette page ({{ data.subdomain.replace(/^https?:\/\//, '') }}).</Alert>
    <Alert v-else-if="data && !data.available" tone="warning">Le domaine personnalisé est compris dans l'offre Pro. <RouterLink to="/gestion/abonnement" class="underline">Voir les offres</RouterLink></Alert>
    <Alert v-if="error">{{ error }}</Alert>

    <form v-if="data?.available" class="card flex flex-wrap items-end gap-3 p-4" @submit.prevent="add">
      <Field id="hostname" label="Nom de domaine" :error="errors.hostname" class="min-w-64 flex-1"><input id="hostname" v-model="hostname" class="input" placeholder="www.maboutique.ci" required /></Field>
      <button class="btn-brand">Ajouter</button>
    </form>

    <section v-for="d in data?.data ?? []" :key="d.id" class="card space-y-3 p-4 text-sm">
      <div class="flex flex-wrap items-center gap-2">
        <a :href="d.url" target="_blank" rel="noopener" class="text-base font-semibold hover:underline">{{ d.hostname }}</a>
        <span v-if="d.verified" class="rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">Actif · https</span>
        <span v-else class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-900">À configurer</span>
        <span v-if="d.is_primary" class="rounded bg-slate-100 px-2 py-0.5 text-xs">Adresse principale</span>
        <span class="ml-auto flex gap-2">
          <button class="btn-secondary px-3 py-1.5" :disabled="checking === d.id" @click="verify(d)">{{ checking === d.id ? 'Vérification…' : 'Vérifier' }}</button>
          <button v-if="d.verified && !d.is_primary" class="btn-ghost px-2" @click="primary(d)">Rendre principal</button>
          <button class="btn-ghost px-2 text-red-600" @click="remove(d)">Retirer</button>
        </span>
      </div>
      <p v-if="d.last_error" class="text-amber-800">{{ d.last_error }}</p>
      <p v-if="d.last_checked_at" class="text-xs text-slate-500">Dernière vérification : {{ formatDateTime(d.last_checked_at) }}</p>
      <div v-if="!d.verified">
        <p class="mb-2 text-slate-600">Chez votre registraire (là où vous avez acheté le domaine), ajoutez ces enregistrements DNS puis cliquez sur « Vérifier » :</p>
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead class="text-xs text-slate-500"><tr><th class="py-1 pr-3">Type</th><th class="pr-3">Nom</th><th class="pr-3">Valeur</th><th>Rôle</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="r in d.records" :key="r.type + r.value">
                <td class="py-1.5 pr-3 font-mono">{{ r.type }}</td>
                <td class="pr-3 font-mono text-xs">{{ r.name }}</td>
                <td class="pr-3 font-mono text-xs"><button type="button" class="text-left hover:text-emerald-700" title="Copier" @click="copy(r.value)">{{ r.value }} ⧉</button></td>
                <td class="text-slate-500">{{ r.purpose }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</template>
