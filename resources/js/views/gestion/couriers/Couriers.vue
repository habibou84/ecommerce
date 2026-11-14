<script setup>
import Icon from '../../../components/Icon.vue'
import { onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import CourierWhatsAppButton from '../../../components/CourierWhatsAppButton.vue'
import DeliveryTabs from '../../../components/DeliveryTabs.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { money } from '../../../lib/format'
import { useAuthStore } from '../../../stores/auth'

const auth = useAuthStore()
// Autres boutiques de l'adresse : le nouveau livreur peut y travailler aussi
const otherStores = () => (auth.profile?.stores ?? []).filter((s) => s.id !== auth.store?.id)

const data = ref(null)
const error = ref('')
const errors = ref({})
const busy = ref(false)
const modal = ref(null) // 'edit' | 'link'
const editing = ref(null)
const link = ref(null)
const notice = ref('')

async function load() {
  try {
    data.value = (await http.get('/couriers')).data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

function open(courier) {
  errors.value = {}
  error.value = ''
  editing.value = courier
    ? { ...courier, pay_rule_id: courier.pay_rule?.id ?? null, zone_ids: courier.zones.map((z) => z.id) }
    : { name: '', phone: '', vehicle_type: 'moto', pay_rule_id: null, cash_limit: null, zone_ids: [], note: '', store_ids: [] }
  modal.value = 'edit'
}

async function save() {
  busy.value = true
  errors.value = {}
  try {
    const payload = { ...editing.value, cash_limit: editing.value.cash_limit === '' ? null : editing.value.cash_limit }
    if (editing.value.id) {
      delete payload.phone
      await http.patch(`/couriers/${editing.value.id}`, payload)
      modal.value = null
    } else {
      const { data: res } = await http.post('/couriers', payload)
      // Nouveau livreur : son accès part sur WhatsApp, sans mot de passe à créer
      link.value = res.data.status === 'active' ? null : { id: res.data.id, name: res.data.name }
      modal.value = link.value ? 'link' : null
    }
    await load()
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

const mapUrl = (l) => `https://www.google.com/maps/search/?api=1&query=${l.latitude},${l.longitude}`
const ago = (at) => {
  const minutes = Math.round((Date.now() - new Date(at)) / 60000)
  return minutes < 1 ? 'à l\'instant' : minutes < 60 ? `il y a ${minutes} min` : `à ${new Date(at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`
}

async function cutAccess(courier) {
  if (!confirm(`Couper l'accès de ${courier.name} ? Son téléphone sera déconnecté ; ses courses restent. Pour revenir, il lui faudra un nouveau lien.`)) return
  try {
    const { data: res } = await http.post(`/couriers/${courier.id}/cut-access`)
    notice.value = res.message
  } catch (e) {
    error.value = errorMessage(e)
  }
}

async function toggleAvailable(courier) {
  await http.patch(`/couriers/${courier.id}`, { is_available: !courier.is_available })
  courier.is_available = !courier.is_available
}

async function deactivate(courier) {
  if (!confirm(`Désactiver ${courier.name} ? Il ne pourra plus se connecter à l'application.`)) return
  try {
    await http.post(`/couriers/${courier.id}/deactivate`)
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  }
}

</script>

<template>
  <div class="space-y-4">
    <DeliveryTabs />
    <div class="flex items-center justify-between gap-3">
      <p class="text-sm text-slate-600">Vos livreurs reçoivent leurs missions dans l'application <strong>/livreur</strong>, sur leur téléphone.</p>
      <button class="btn-brand shrink-0" @click="open(null)">+ Livreur</button>
    </div>
    <Alert v-if="error && !modal">{{ error }}</Alert>
    <Alert v-if="notice" tone="success">{{ notice }}</Alert>

    <div v-if="data" class="card">
      <p v-if="!data.data.length" class="p-6 text-sm text-slate-500">Aucun livreur. Ajoutez-en un : il reçoit ses courses et son accès sur WhatsApp, sans mot de passe.</p>
      <ul class="divide-y divide-slate-100">
        <li v-for="c in data.data" :key="c.id" class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm" :class="{ 'opacity-50': c.status === 'inactive' }">
          <span class="min-w-0 flex-1">
            <span class="block font-medium">{{ c.name }} <span class="font-normal text-slate-500">· {{ c.phone_display }} · {{ data.vehicles[c.vehicle_type] }}</span></span>
            <span class="block text-slate-500">
              {{ c.zones.map((z) => z.name).join(', ') || 'Toutes zones' }} · {{ c.pay_rule?.name ?? 'sans règle de paie' }}
              <template v-if="c.active_missions"> · {{ c.active_missions }} mission(s) en cours</template>
            </span>
            <a v-if="c.location" :href="mapUrl(c.location)" target="_blank" class="block text-emerald-700 hover:underline"><Icon name="pin" :size="16" class="mr-1 inline-block align-[-3px]" />Position {{ ago(c.location.at) }}</a>
          </span>
          <span v-if="c.status === 'invited'" class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-900">Invité</span>
          <span v-else-if="c.status === 'inactive'" class="rounded bg-slate-100 px-2 py-0.5 text-xs">Désactivé</span>
          <button v-else class="rounded-full px-2 py-0.5 text-xs" :class="c.is_available ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'" @click="toggleAvailable(c)">
            {{ c.is_available ? 'Disponible' : 'Indisponible' }}
          </button>
          <span class="w-28 text-right" :class="{ 'font-semibold text-amber-800': c.cash_limit && c.cash_held > c.cash_limit }">{{ money(c.cash_held) }} <span class="block text-xs font-normal text-slate-400">espèces</span></span>
          <span class="flex gap-1">
            <CourierWhatsAppButton v-if="c.status !== 'inactive'" :courier-id="c.id" :label="c.status === 'invited' ? 'Envoyer son accès' : 'WhatsApp'" small />
            <button v-if="c.status === 'active'" class="btn-ghost px-2 py-1 text-slate-600" title="Téléphone perdu, livreur parti : déconnecter son téléphone" @click="cutAccess(c)">Couper l'accès</button>
            <RouterLink v-if="c.status !== 'invited'" :to="`/gestion/livraisons/paie?livreur=${c.id}`" class="btn-secondary px-2 py-1">Paie · {{ money(c.unpaid) }}</RouterLink>
            <button class="btn-ghost px-2 py-1" @click="open(c)">Modifier</button>
            <button v-if="c.status !== 'inactive'" class="btn-ghost px-2 py-1 text-red-600" @click="deactivate(c)">✕</button>
          </span>
        </li>
      </ul>
    </div>

    <Modal v-if="modal === 'edit'" :title="editing.id ? editing.name : 'Nouveau livreur'" @close="modal = null">
      <form class="space-y-4" @submit.prevent="save">
        <Alert v-if="error">{{ error }}</Alert>
        <Field id="cr-name" label="Nom" :error="errors.name"><input id="cr-name" v-model="editing.name" class="input" required maxlength="80" /></Field>
        <Field v-if="!editing.id" id="cr-phone" label="Téléphone" :error="errors.phone"><input id="cr-phone" v-model="editing.phone" type="tel" class="input" required /></Field>
        <div class="grid grid-cols-2 gap-3">
          <Field id="cr-vehicle" label="Véhicule">
            <select id="cr-vehicle" v-model="editing.vehicle_type" class="input"><option v-for="(label, key) in data.vehicles" :key="key" :value="key">{{ label }}</option></select>
          </Field>
          <Field id="cr-limit" label="Plafond d'espèces" :error="errors.cash_limit" hint="Alerte au-delà">
            <input id="cr-limit" v-model="editing.cash_limit" type="number" min="0" class="input" placeholder="Aucun" />
          </Field>
        </div>
        <Field id="cr-rule" label="Règle de paie">
          <select id="cr-rule" v-model="editing.pay_rule_id" class="input"><option :value="null">Aucune</option><option v-for="r in data.pay_rules" :key="r.id" :value="r.id">{{ r.name }}</option></select>
        </Field>
        <fieldset>
          <legend class="label">Zones couvertes</legend>
          <div class="flex flex-wrap gap-2">
            <label v-for="z in data.zones" :key="z.id" class="flex items-center gap-1.5 rounded-full border border-slate-200 px-3 py-1 text-sm">
              <input v-model="editing.zone_ids" type="checkbox" :value="z.id" class="accent-emerald-700" /> {{ z.name }}
            </label>
          </div>
        </fieldset>
        <fieldset v-if="!editing.id && otherStores().length">
          <legend class="label">Travaille aussi pour</legend>
          <div class="flex flex-wrap gap-2">
            <label v-for="st in otherStores()" :key="st.id" class="flex items-center gap-1.5 rounded-full border border-slate-200 px-3 py-1 text-sm">
              <input v-model="editing.store_ids" type="checkbox" :value="st.id" class="accent-emerald-700" /> {{ st.name }}
            </label>
          </div>
          <p class="mt-1 text-xs text-slate-500">Une seule application pour toutes ses livraisons ; chaque boutique garde ses zones et sa paie pour lui.</p>
        </fieldset>
        <button class="btn-brand w-full" :disabled="busy">{{ editing.id ? 'Enregistrer' : 'Ajouter et créer le lien' }}</button>
      </form>
    </Modal>

    <Modal v-if="modal === 'link'" :title="`Accès de ${link.name}`" @close="modal = null">
      <div class="space-y-3 text-sm">
        <p>Envoyez-lui son lien sur WhatsApp : il entre <strong>sans mot de passe</strong>.</p>
        <CourierWhatsAppButton :courier-id="link.id" label="Envoyer par WhatsApp" />
      </div>
    </Modal>
  </div>
</template>
