<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import Field from '../../components/Field.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { formatDate } from '../../lib/format'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()

const members = ref([])
const invitations = ref([])
const roles = ref([])
const loading = ref(true)
const error = ref('')

const form = reactive({ phone: '', name: '', role: 'manager' })
// Autres boutiques de l'adresse dont on gère l'équipe : la personne peut les rejoindre avec la même invitation
const manageable = ref([])
const otherStores = computed(() => manageable.value.filter((s) => !s.is_current))
const chosen = reactive({}) // id de boutique → cochée
const differentRoles = ref(false)
const storeRoles = reactive({}) // id de boutique → rôle (si rôle différent par boutique)
// Boutiques d'un membre en cours de modification : id de boutique → rôle ('' : n'y travaille pas)
const editing = ref(null)
const editRoles = reactive({})
const formErrors = ref({})
const inviting = ref(false)
const sent = ref(null) // dernière invitation : lien et message WhatsApp
const copied = ref(false)

async function load() {
  loading.value = true
  try {
    const [m, i, r, st] = await Promise.all([http.get('/team/members'), http.get('/team/invitations'), http.get('/team/roles'), http.get('/team/stores')])
    members.value = m.data.data
    invitations.value = i.data.data
    roles.value = r.data.data
    manageable.value = st.data.data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function invite() {
  inviting.value = true
  formErrors.value = {}
  error.value = ''
  try {
    const stores = Object.fromEntries(otherStores.value.filter((s) => chosen[s.id]).map((s) => [s.id, differentRoles.value ? storeRoles[s.id] ?? form.role : form.role]))
    const { data } = await http.post('/team/invitations', { ...form, stores })
    sent.value = data
    copied.value = false
    invitations.value.unshift(data.data)
    Object.assign(form, { phone: '', name: '' })
    Object.keys(chosen).forEach((k) => delete chosen[k])
    differentRoles.value = false
  } catch (e) {
    formErrors.value = fieldErrors(e)
    if (!Object.keys(formErrors.value).length) error.value = errorMessage(e)
  } finally {
    inviting.value = false
  }
}

async function copyLink() {
  try {
    await navigator.clipboard.writeText(sent.value.link)
    copied.value = true
  } catch {
    copied.value = false
  }
}

async function cancelInvitation(invitation) {
  await http.delete(`/team/invitations/${invitation.id}`)
  invitations.value = invitations.value.filter((i) => i.id !== invitation.id)
}

async function updateMember(member, changes) {
  error.value = ''
  try {
    const { data } = await http.patch(`/team/members/${member.id}`, changes)
    Object.assign(member, data.data)
  } catch (e) {
    error.value = errorMessage(e)
    await load()
  }
}

async function removeMember(member) {
  if (!confirm(`Retirer ${member.name} de l'équipe de ${auth.store?.name} ?${elsewhere(member).length ? ' Ses autres boutiques ne changent pas (bouton « Boutiques »).' : ''}`)) return
  error.value = ''
  try {
    await http.delete(`/team/members/${member.id}`)
    members.value = members.value.filter((m) => m.id !== member.id)
  } catch (e) {
    error.value = errorMessage(e)
  }
}

function editStores(member) {
  editing.value = member.id
  for (const s of otherStores.value) editRoles[s.id] = member.stores.find((x) => x.store_id === s.id)?.role ?? ''
}

async function saveStores(member) {
  error.value = ''
  try {
    const stores = Object.fromEntries(otherStores.value.map((s) => [s.id, editRoles[s.id] || null]))
    const { data } = await http.put(`/team/members/${member.id}/stores`, { stores })
    Object.assign(member, data.data)
    editing.value = null
  } catch (e) {
    error.value = errorMessage(e)
  }
}

// Boutiques du membre autres que celle-ci
const elsewhere = (member) => (member.stores ?? []).filter((s) => s.store_id !== auth.store?.id)

const invitableRoles = () => roles.value.filter((r) => r.invitable)
const editable = (member) => !member.is_owner && member.user_id !== auth.user?.id
</script>

<template>
  <div class="space-y-6">
    <Alert v-if="error">{{ error }}</Alert>

    <section class="card p-4 sm:p-6">
      <h2 class="font-semibold">Inviter une personne</h2>
      <p class="mt-1 text-sm text-slate-600">Vous recevez un lien à lui envoyer par WhatsApp. Il est valable 7 jours.</p>

      <form class="mt-4 grid gap-4 sm:grid-cols-3" @submit.prevent="invite">
        <Field id="invite-phone" label="Numéro de téléphone" :error="formErrors.phone">
          <input id="invite-phone" v-model="form.phone" class="input" type="tel" inputmode="tel" required />
        </Field>
        <Field id="invite-name" label="Nom (facultatif)" :error="formErrors.name">
          <input id="invite-name" v-model="form.name" class="input" maxlength="80" />
        </Field>
        <Field id="invite-role" label="Rôle" :error="formErrors.role">
          <select id="invite-role" v-model="form.role" class="input">
            <option v-for="role in invitableRoles()" :key="role.value" :value="role.value">{{ role.label }}</option>
          </select>
        </Field>
        <fieldset v-if="otherStores.length" class="space-y-2 sm:col-span-3">
          <legend class="label">Boutiques</legend>
          <p class="text-sm text-slate-600">La personne rejoint {{ auth.store?.name }} et, avec le même lien, les boutiques cochées.</p>
          <div v-for="s in otherStores" :key="s.id" class="flex flex-wrap items-center gap-3">
            <label class="flex min-w-[10rem] items-center gap-2 text-sm"><input v-model="chosen[s.id]" type="checkbox" class="h-4 w-4 accent-emerald-700" /> {{ s.name }}</label>
            <select v-if="differentRoles && chosen[s.id]" v-model="storeRoles[s.id]" class="input w-auto py-1.5 text-sm" :aria-label="`Rôle dans ${s.name}`">
              <option v-for="role in invitableRoles()" :key="role.value" :value="role.value">{{ role.label }}</option>
            </select>
          </div>
          <button v-if="!differentRoles" type="button" class="text-sm text-emerald-700 underline" @click="differentRoles = true; otherStores.forEach((s) => (storeRoles[s.id] = form.role))">Rôle différent par boutique</button>
          <p v-if="formErrors.stores" class="text-sm text-red-600">{{ formErrors.stores }}</p>
        </fieldset>
        <p class="text-sm text-slate-500 sm:col-span-2">{{ roles.find((r) => r.value === form.role)?.description }}</p>
        <button class="btn-brand" :disabled="inviting">{{ inviting ? 'Création…' : 'Créer l\'invitation' }}</button>
      </form>

      <div v-if="sent" class="mt-4 space-y-3 rounded-lg bg-emerald-50 p-4">
        <p class="text-sm text-emerald-900">Invitation prête pour {{ sent.data.phone }}. Envoyez-lui le lien :</p>
        <div class="flex flex-col gap-2 sm:flex-row">
          <a :href="sent.whatsapp_url" target="_blank" rel="noopener" class="btn-brand">Envoyer par WhatsApp</a>
          <button class="btn-secondary" @click="copyLink">{{ copied ? 'Lien copié ✓' : 'Copier le lien' }}</button>
        </div>
      </div>
    </section>

    <section class="card">
      <h2 class="border-b border-slate-100 px-4 py-3 font-semibold sm:px-6">Équipe</h2>
      <p v-if="loading" class="p-4 text-sm text-slate-500 sm:px-6">Chargement…</p>
      <ul v-else class="divide-y divide-slate-100">
        <li v-for="member in members" :key="member.id" class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:px-6">
          <div class="min-w-0 flex-1">
            <p class="font-medium">
              {{ member.name }}
              <span v-if="member.status === 'suspended'" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900">Suspendu</span>
            </p>
            <p class="text-sm text-slate-500">{{ member.phone }}</p>
            <p v-if="elsewhere(member).length" class="mt-0.5 text-xs text-slate-600">Aussi : {{ elsewhere(member).map((x) => `${x.name} (${x.role_label})`).join(' · ') }}</p>
            <div v-if="editing === member.id" class="mt-2 space-y-2 rounded-lg bg-slate-50 p-3">
              <div v-for="s in otherStores" :key="s.id" class="flex flex-wrap items-center gap-2 text-sm">
                <span class="min-w-[8rem] font-medium">{{ s.name }}</span>
                <select v-model="editRoles[s.id]" class="input w-auto py-1.5 text-sm" :aria-label="`Rôle dans ${s.name}`">
                  <option value="">N'y travaille pas</option>
                  <option v-for="role in invitableRoles()" :key="role.value" :value="role.value">{{ role.label }}</option>
                </select>
              </div>
              <div class="flex gap-2"><button class="btn-brand px-3 py-1.5 text-sm" @click="saveStores(member)">Enregistrer</button><button class="btn-ghost px-3 py-1.5 text-sm" @click="editing = null">Annuler</button></div>
            </div>
          </div>
          <template v-if="editable(member)">
            <select v-if="invitableRoles().some((r) => r.value === member.role)" class="input sm:w-56" :value="member.role" @change="updateMember(member, { role: $event.target.value })">
              <option v-for="role in invitableRoles()" :key="role.value" :value="role.value">{{ role.label }}</option>
            </select>
            <span v-else class="text-sm text-slate-600 sm:w-56">{{ member.role_label }}<template v-if="member.role === 'courier'"> · partage dans Vue d'ensemble › Livreurs</template></span>
            <div class="flex gap-2">
              <button class="btn-secondary px-3 py-2" @click="updateMember(member, { status: member.status === 'active' ? 'suspended' : 'active' })">
                {{ member.status === 'active' ? 'Suspendre' : 'Réactiver' }}
              </button>
              <button v-if="otherStores.length && member.role !== 'courier'" class="btn-secondary px-3 py-2" @click="editStores(member)">Boutiques</button>
              <button class="btn-ghost px-3 py-2 text-red-600" @click="removeMember(member)">Retirer</button>
            </div>
          </template>
          <span v-else class="text-sm text-slate-600">{{ member.role_label }}</span>
        </li>
      </ul>
    </section>

    <section v-if="invitations.length" class="card">
      <h2 class="border-b border-slate-100 px-4 py-3 font-semibold sm:px-6">Invitations en attente</h2>
      <ul class="divide-y divide-slate-100">
        <li v-for="invitation in invitations" :key="invitation.id" class="flex items-center gap-3 px-4 py-3 sm:px-6">
          <div class="min-w-0 flex-1">
            <p class="font-medium">{{ invitation.name || invitation.phone }}</p>
            <p class="text-sm text-slate-500">{{ invitation.role_label }}<template v-if="invitation.other_stores?.length"> · aussi {{ invitation.other_stores.map((x) => `${x.name} (${x.role})`).join(', ') }}</template> · expire le {{ formatDate(invitation.expires_at) }}</p>
          </div>
          <button class="btn-ghost px-3 py-2 text-red-600" @click="cancelInvitation(invitation)">Annuler</button>
        </li>
      </ul>
    </section>
  </div>
</template>
