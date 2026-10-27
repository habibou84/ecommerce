<script setup>
import { onMounted, reactive, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import Field from '../../components/Field.vue'
import Modal from '../../components/Modal.vue'
import http, { download, errorMessage, fieldErrors } from '../../lib/http'
import { formatDate, formatDateTime } from '../../lib/format'

// Clés d'accès en cartes à QR code : cartes « Membre fondateur » (à vie) et clés à durée, par lots
const data = ref(null)
const error = ref('')
const busy = ref(false)
const creating = ref(null)
const errors = ref({})
const open = ref(null) // lot affiché avec ses clés
const filter = ref('')
const STATE = { available: ['À activer', 'bg-slate-100 text-slate-700'], active: ['Active', 'bg-emerald-100 text-emerald-800'], paused: ['En pause', 'bg-amber-100 text-amber-900'], ended: ['Terminée', 'bg-slate-100 text-slate-500'], revoked: ['Annulée', 'bg-red-100 text-red-700'] }

async function load() {
  try {
    data.value = (await http.get('/console/access-keys')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

function startBatch(kind) {
  errors.value = {}
  creating.value = reactive({ kind, plan: kind === 'founder' ? 'pro' : 'starter', months: kind === 'timed' ? 6 : null, quantity: kind === 'founder' ? 200 : 50, distributor_name: '', distributor_phone: '', note: '' })
}

async function create() {
  busy.value = true
  errors.value = {}
  try {
    const batch = (await http.post('/console/access-keys', creating.value)).data.data
    creating.value = null
    await load()
    open.value = batch
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function show(batch) {
  filter.value = ''
  open.value = (await http.get(`/console/access-keys/${batch.id}`)).data.data
}

async function print(batch) {
  busy.value = true
  try {
    await download(`/console/access-keys/${batch.id}/cards`, `cles-${batch.id}.pdf`)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function revoke(key) {
  if (!window.confirm(`Annuler la clé n° ${key.number} (${key.code}) ?${key.store ? ` ${key.store.name} repasse à l'offre gratuite.` : ' Elle ne pourra plus être activée.'}`)) return
  open.value = (await http.post(`/console/access-keys/keys/${key.id}/revoke`)).data.data
  load()
}

const visibleKeys = () => (open.value?.keys ?? []).filter((k) => !filter.value || k.state === filter.value)
const offer = (b) => `${b.plan} ${b.kind === 'founder' ? 'à vie' : `pendant ${b.months} mois`}`
</script>

<template>
  <div v-if="data" class="space-y-5">
    <Alert v-if="error">{{ error }}</Alert>

    <section class="card space-y-3 p-4 text-sm">
      <p class="text-slate-600">
        Des cartes à QR code à distribuer : la personne scanne, crée sa boutique (ou se connecte) et la clé s'active. Une clé sert une seule fois.
        L'assistant IA n'est dans aucune offre : il reste à la recharge. Une carte fondateur se met en pause après {{ data.idle_days }} jours sans commande, et se rallume à la commande suivante.
      </p>
      <div class="flex flex-wrap gap-2">
        <button class="btn-brand" @click="startBatch('founder')">🏅 Nouvelles cartes fondateur</button>
        <button class="btn-ghost" @click="startBatch('timed')">Nouvelles clés à durée</button>
      </div>
    </section>

    <section class="card p-4 text-sm">
      <h3 class="mb-2 font-semibold">Lots</h3>
      <p v-if="!data.batches.length" class="text-slate-500">Aucun lot pour l'instant.</p>
      <ul class="divide-y divide-slate-100">
        <li v-for="b in data.batches" :key="b.id" class="flex flex-wrap items-center gap-3 py-3">
          <span class="min-w-0 flex-1">
            <span class="font-medium">{{ b.kind === 'founder' ? '🏅 Fondateur' : 'Durée' }} · {{ b.quantity }} clés · offre {{ offer(b) }}</span>
            <span class="block text-xs text-slate-500">
              Lot n° {{ b.id }} du {{ formatDate(b.created_at) }}<template v-if="b.distributor_name"> · distribué par {{ b.distributor_name }}</template><template v-if="b.note"> · {{ b.note }}</template>
            </span>
            <span class="block text-xs text-slate-600">
              {{ b.counts.activated }} activées · {{ b.counts.active }} actives<template v-if="b.counts.paused"> · {{ b.counts.paused }} en pause</template><template v-if="b.counts.ended"> · {{ b.counts.ended }} terminées</template><template v-if="b.counts.revoked"> · {{ b.counts.revoked }} annulées</template> · {{ b.counts.available }} à activer
            </span>
          </span>
          <button class="btn-ghost px-2" :disabled="busy || !b.counts.available" @click="print(b)">Imprimer les cartes</button>
          <button class="btn-ghost px-2" @click="show(b)">Voir les clés</button>
        </li>
      </ul>
    </section>

    <Modal v-if="creating" :title="creating.kind === 'founder' ? 'Nouvelles cartes fondateur' : 'Nouvelles clés à durée'" @close="creating = null">
      <form class="space-y-3" @submit.prevent="create">
        <Field id="k-plan" label="Offre donnée" :error="errors.plan">
          <select id="k-plan" v-model="creating.plan" class="input">
            <option v-for="p in data.plans" :key="p.code" :value="p.code">{{ p.name }}</option>
          </select>
        </Field>
        <Field v-if="creating.kind === 'timed'" id="k-months" label="Durée (mois)" :error="errors.months">
          <input id="k-months" v-model.number="creating.months" type="number" min="1" max="36" class="input" />
        </Field>
        <Field id="k-qty" label="Nombre de cartes" :error="errors.quantity" :hint="creating.kind === 'founder' ? 'Numérotées de 1 au total (ex. « n° 042 / 200 »).' : ''">
          <input id="k-qty" v-model.number="creating.quantity" type="number" min="1" max="1000" class="input" />
        </Field>
        <Field id="k-dist" label="Distributeur (facultatif)" :error="errors.distributor_name">
          <input id="k-dist" v-model="creating.distributor_name" class="input" placeholder="Boutique Mariam, Adjamé" />
        </Field>
        <Field id="k-dphone" label="Téléphone du distributeur (facultatif)" :error="errors.distributor_phone">
          <input id="k-dphone" v-model="creating.distributor_phone" class="input" inputmode="tel" />
        </Field>
        <Field id="k-note" label="Note (facultatif)" :error="errors.note">
          <input id="k-note" v-model="creating.note" class="input" maxlength="255" placeholder="Salon du 15 décembre" />
        </Field>
        <button class="btn-brand w-full" :disabled="busy">{{ busy ? 'Un instant…' : 'Créer les clés' }}</button>
      </form>
    </Modal>

    <Modal v-if="open" :title="`Lot n° ${open.id} · ${open.kind === 'founder' ? 'fondateur' : 'durée'} · ${open.quantity} clés`" @close="open = null">
      <div class="space-y-3 text-sm">
        <div class="flex flex-wrap items-center gap-2">
          <select v-model="filter" class="input w-auto" aria-label="Filtrer les clés">
            <option value="">Toutes</option>
            <option v-for="(s, key) in STATE" :key="key" :value="key">{{ s[0] }}</option>
          </select>
          <button class="btn-ghost px-2" :disabled="busy || !open.counts.available" @click="print(open)">Imprimer les cartes à activer</button>
        </div>
        <ul class="max-h-[60vh] divide-y divide-slate-100 overflow-y-auto">
          <li v-for="k in visibleKeys()" :key="k.id" class="flex flex-wrap items-center gap-2 py-2">
            <span class="w-12 text-slate-500">n° {{ k.number }}</span>
            <span class="font-mono">{{ k.code }}</span>
            <span class="rounded px-1.5 text-xs" :class="STATE[k.state]?.[1]">{{ STATE[k.state]?.[0] ?? k.state }}</span>
            <span class="min-w-0 flex-1 truncate text-xs text-slate-600">
              <RouterLink v-if="k.store" :to="`/console/boutiques/${k.store.id}`" class="text-emerald-700 underline">{{ k.store.name }}</RouterLink>
              <template v-if="k.activated_at"> · le {{ formatDateTime(k.activated_at) }}</template>
              <template v-if="k.expires_at"> · jusqu'au {{ formatDate(k.expires_at) }}</template>
            </span>
            <button v-if="k.state !== 'revoked'" class="text-xs text-red-700 underline" @click="revoke(k)">Annuler</button>
          </li>
        </ul>
      </div>
    </Modal>
  </div>
</template>
