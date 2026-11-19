<script setup>
// Espace du livreur, indépendant des boutiques : demandes des boutiques (accepter, refuser), ses boutiques (quitter),
// son profil (photo, véhicule, communes, annuaire, pièce d'identité) et, sans boutique, son numéro à donner (code QR).
import { computed, onMounted, ref } from 'vue'
import Alert from './Alert.vue'
import http, { errorMessage } from '../lib/http'
import { money } from '../lib/format'

// showNumber : sans boutique, son numéro et son code QR ; compactStores : liste des boutiques repliée (déjà affichée par la page)
const props = defineProps({ showNumber: { type: Boolean, default: false }, compactStores: { type: Boolean, default: false } })
const emit = defineEmits(['changed'])

const space = ref(null)
const places = ref([])
const error = ref('')
const notice = ref('')
const busy = ref(false)
const leaving = ref(null)
const search = ref('')

async function load() {
  try {
    const [s, p] = await Promise.all([http.get('/courier-space'), places.value.length ? null : http.get('/courier-space/places')])
    space.value = s.data.data
    if (p) places.value = p.data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function run(call, message) {
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    space.value = (await call()).data.data
    if (message) notice.value = message
    emit('changed', space.value)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
const accept = (r) => run(() => http.post(`/courier-space/requests/${r.courier_id}/accept`), `Vous livrez maintenant pour ${r.store} ✓`)
const decline = (r) => run(() => http.post(`/courier-space/requests/${r.courier_id}/decline`), `Demande de ${r.store} refusée.`)
function leave(s) {
  leaving.value = null
  run(() => http.post(`/courier-space/stores/${s.courier_id}/leave`), `Vous ne livrez plus pour ${s.name}.`)
}
const saveProfile = (fields) => run(() => http.put('/courier-space/profile', fields))
function upload(kind, event) {
  const file = event.target.files[0]
  if (!file) return
  const body = new FormData()
  body.append(kind === 'photo' ? 'photo' : 'document', file)
  run(() => http.post(kind === 'photo' ? '/courier-space/photo' : '/courier-space/id-document', body),
    kind === 'photo' ? 'Photo enregistrée ✓' : 'Pièce d\'identité enregistrée ✓')
  event.target.value = ''
}

// Communes et quartiers
const chosen = computed(() => new Set((space.value?.profile.places ?? []).map((p) => p.id)))
const communes = computed(() => places.value.filter((p) => p.type === 'commune'))
const found = computed(() => {
  const q = search.value.trim().toLowerCase()
  return q.length < 2 ? [] : places.value.filter((p) => !chosen.value.has(p.id) && p.name.toLowerCase().includes(q)).slice(0, 8)
})
function togglePlace(id) {
  const ids = new Set(chosen.value)
  ids.has(id) ? ids.delete(id) : ids.add(id)
  search.value = ''
  saveProfile({ place_ids: [...ids] })
}
const otherPlaces = computed(() => (space.value?.profile.places ?? []).filter((p) => !communes.value.some((c) => c.id === p.id)))

const day = (iso) => new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })
defineExpose({ reload: load })
</script>

<template>
  <div v-if="space" class="space-y-4">
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="notice" tone="success">{{ notice }}</Alert>

    <!-- Sans boutique : son numéro et son code à donner -->
    <section v-if="showNumber && !space.stores.length" class="space-y-2 rounded-xl bg-white p-4 text-center shadow-sm">
      <p class="font-display text-lg font-bold">Bienvenue, {{ space.name.split(' ')[0] }}</p>
      <p class="text-sm text-slate-600">Aucune boutique ne vous a encore ajouté. Donnez ce numéro ou faites scanner ce code à la boutique.</p>
      <img :src="space.qr" alt="Code QR de votre numéro" class="mx-auto h-44 w-44 bg-white p-2" />
      <p class="font-display text-2xl font-bold tracking-wide">{{ space.phone_display }}</p>
      <p class="explain-detail">Quand une boutique vous ajoute, la demande apparaît ici.</p>
    </section>

    <!-- Demandes des boutiques -->
    <section v-for="r in space.requests" :key="`req-${r.courier_id}`" class="space-y-2 rounded-xl border-2 border-amber-400 bg-amber-50 p-4">
      <p class="font-semibold text-amber-900">🔔 {{ r.store }} veut que vous livriez pour elle</p>
      <p class="text-sm text-slate-700">
        <template v-if="r.pay_rule">Paie : {{ r.pay_rule }}</template><template v-if="r.pay_rule && r.cash_limit"> · </template><template v-if="r.cash_limit">plafond d'espèces {{ money(r.cash_limit) }}</template>
        <template v-if="r.requested_at"><br />Demande du {{ day(r.requested_at) }}</template>
      </p>
      <p v-if="r.require_id && !space.profile.has_id_document" class="rounded-lg bg-white p-2 text-sm text-amber-900">🪪 {{ r.store }} demande votre pièce d'identité : ajoutez-la plus bas, puis acceptez.</p>
      <div class="grid grid-cols-2 gap-2">
        <button class="btn-secondary" :disabled="busy" @click="decline(r)">Refuser</button>
        <button class="btn-brand" :disabled="busy || (r.require_id && !space.profile.has_id_document)" @click="accept(r)">Accepter</button>
      </div>
    </section>

    <!-- Ses boutiques -->
    <details v-if="space.stores.length && compactStores" class="rounded-xl bg-white p-4 text-sm shadow-sm">
      <summary class="cursor-pointer font-semibold">Quitter une boutique</summary>
      <div v-for="s in space.stores" :key="s.courier_id" class="flex items-center gap-2 border-b border-slate-100 py-2 last:border-0">
        <span class="flex-1">{{ s.name }}</span>
        <template v-if="leaving === s.courier_id">
          <button class="btn-ghost px-2 py-1 text-sm" @click="leaving = null">Annuler</button>
          <button class="btn-danger px-2 py-1 text-sm" :disabled="busy" @click="leave(s)">Quitter</button>
        </template>
        <button v-else class="text-sm text-slate-500 underline" @click="leaving = s.courier_id">Quitter</button>
      </div>
      <p class="explain-detail">Pour quitter une boutique : plus de course en cours, et ses espèces versées.</p>
    </details>
    <section v-else-if="space.stores.length" class="space-y-1 rounded-xl bg-white p-4 shadow-sm">
      <h2 class="font-semibold">Mes boutiques</h2>
      <div v-for="s in space.stores" :key="s.courier_id" class="flex items-center gap-2 border-b border-slate-100 py-2 text-sm last:border-0">
        <span class="flex-1">{{ s.name }}</span>
        <template v-if="leaving === s.courier_id">
          <button class="btn-ghost px-2 py-1 text-sm" @click="leaving = null">Annuler</button>
          <button class="btn-danger px-2 py-1 text-sm" :disabled="busy" @click="leave(s)">Quitter</button>
        </template>
        <button v-else class="text-sm text-slate-500 underline" @click="leaving = s.courier_id">Quitter</button>
      </div>
      <p class="explain-detail">Pour quitter une boutique : plus de course en cours, et ses espèces versées.</p>
    </section>

    <!-- Profil -->
    <section class="space-y-3 rounded-xl bg-white p-4 shadow-sm">
      <h2 class="font-semibold">Mon profil livreur</h2>
      <div class="flex items-center gap-3">
        <img v-if="space.profile.photo_url" :src="space.profile.photo_url" alt="Votre photo" class="h-16 w-16 rounded-full object-cover" />
        <span v-else class="flex h-16 w-16 items-center justify-center rounded-full bg-emerald-50 text-2xl" aria-hidden="true">🙂</span>
        <label class="btn-secondary cursor-pointer px-3 py-1.5 text-sm">{{ space.profile.photo_url ? 'Changer la photo' : 'Ajouter ma photo' }}
          <input type="file" accept="image/*" capture="user" class="sr-only" @change="upload('photo', $event)" />
        </label>
      </div>

      <div>
        <p class="label">Véhicule</p>
        <div class="flex flex-wrap gap-2">
          <button v-for="(label, key) in space.vehicles" :key="key" type="button" class="rounded-full border px-3 py-1.5 text-sm"
                  :class="space.profile.vehicle_type === key ? 'border-emerald-600 bg-emerald-50 font-medium' : 'border-field'" :disabled="busy" @click="saveProfile({ vehicle_type: key })">{{ label }}</button>
        </div>
      </div>

      <div>
        <p class="label">Où je livre</p>
        <div class="flex flex-wrap gap-2">
          <button v-for="c in communes" :key="c.id" type="button" class="rounded-full border px-3 py-1.5 text-sm"
                  :class="chosen.has(c.id) ? 'border-emerald-600 bg-emerald-50 font-medium' : 'border-field'" :disabled="busy" @click="togglePlace(c.id)">{{ c.name }}</button>
          <button v-for="p in otherPlaces" :key="p.id" type="button" class="rounded-full border border-emerald-600 bg-emerald-50 px-3 py-1.5 text-sm font-medium" :disabled="busy" @click="togglePlace(p.id)">{{ p.name }} ✕</button>
        </div>
        <input v-model="search" class="input mt-2" placeholder="Ajouter un quartier ou une autre commune" />
        <div v-if="found.length" class="mt-1 flex flex-wrap gap-2">
          <button v-for="p in found" :key="p.id" type="button" class="rounded-full border border-field px-3 py-1.5 text-sm" @click="togglePlace(p.id)">+ {{ p.name }}<span v-if="p.parent" class="text-slate-400"> · {{ p.parent }}</span></button>
        </div>
      </div>

      <label class="flex items-start gap-2 text-sm">
        <input type="checkbox" class="mt-0.5 h-4 w-4 accent-emerald-700" :checked="space.profile.listed" :disabled="busy" @change="saveProfile({ listed: $event.target.checked })" />
        <span>Apparaître dans l'annuaire des livreurs <span class="explain-detail block">Les boutiques vous trouvent par commune et vous envoient une demande. Votre numéro reste caché.</span></span>
      </label>

      <div class="rounded-lg border p-3" :class="space.profile.has_id_document ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50'">
        <p class="text-sm font-medium">{{ space.profile.has_id_document ? '🪪 Pièce d\'identité ajoutée ✓' : '🪪 Pièce d\'identité (facultative)' }}</p>
        <p v-if="!space.profile.has_id_document" class="text-sm text-slate-600">Certaines boutiques l'exigent : en l'ajoutant, vous avez plus de chances d'être choisi.</p>
        <p class="explain-detail">Une boutique peut aussi vous la demander plus tard. Seules vos boutiques la voient.</p>
        <label class="btn-secondary mt-2 inline-flex cursor-pointer px-3 py-1.5 text-sm">{{ space.profile.has_id_document ? 'Remplacer' : 'Ajouter ma pièce' }}
          <input type="file" accept="image/*,application/pdf" capture="environment" class="sr-only" @change="upload('document', $event)" />
        </label>
      </div>
    </section>
  </div>
  <p v-else-if="!error" class="p-6 text-center text-sm text-slate-500">Chargement…</p>
  <Alert v-else>{{ error }}</Alert>
</template>
