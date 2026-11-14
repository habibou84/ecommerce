<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import DeliveryTabs from '../../../components/DeliveryTabs.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { money } from '../../../lib/format'

const data = ref(null)
const error = ref('')
const editing = ref(null)
const errors = ref({})

// Les trois façons de faire livrer une commande confirmée, dites simplement
const MODES = [
  ['off', 'Je choisis moi-même', 'À chaque commande, vous choisissez le livreur ou l\'entreprise dans la liste. Rien n\'est présélectionné.', false],
  ['suggest', 'Jibiat Marchand propose, je valide', 'Quand vous appuyez sur « Faire livrer », le bon livreur est déjà choisi. Vous n\'avez qu\'à valider (ou changer).', true],
  ['auto', 'Envoi automatique', 'Dès que la commande est confirmée, la course part toute seule, sans action de votre part.', false],
]

// Cas particuliers prêts à l'emploi : ouvrent la fenêtre déjà remplie
const TEMPLATES = [
  { name: 'Colis fragiles', action: 'own_courier', fragile: 'true', text: 'Colis fragiles → mes livreurs' },
  { name: 'Grosses commandes', action: 'manual', min_amount: 50000, text: 'Dès 50 000 F → je choisis moi-même' },
  { name: 'Petits colis', action: 'cheapest', max_weight_kg: 2, text: 'Jusqu\'à 2 kg → le moins cher' },
]

async function load() {
  try {
    data.value = (await http.get('/shipping-rules')).data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function settings(payload) {
  error.value = ''
  try {
    data.value = (await http.patch('/shipping-rules', payload)).data
  } catch (e) {
    error.value = errorMessage(e)
  }
}

function move(index, step) {
  const ids = data.value.data.map((r) => r.id)
  const [id] = ids.splice(index, 1)
  ids.splice(index + step, 0, id)
  settings({ order: ids })
}

function open(rule = null, template = null) {
  errors.value = {}
  const c = rule?.conditions ?? {}
  editing.value = {
    id: rule?.id, name: rule?.name ?? '', action: rule?.action ?? 'own_courier', carrier_connection_id: rule?.carrier_connection_id ?? null, is_active: rule?.is_active ?? true,
    zones: [...(c.delivery_zone_ids ?? [])], fragile: c.is_fragile === undefined || c.is_fragile === null ? '' : String(c.is_fragile), min_amount: c.min_amount ?? '', max_amount: c.max_amount ?? '', max_weight_kg: c.max_weight_g ? c.max_weight_g / 1000 : '',
  }
  if (template) {
    const { text, ...fields } = template
    Object.assign(editing.value, fields)
  }
}

async function save() {
  const r = editing.value
  const payload = {
    name: r.name, action: r.action, carrier_connection_id: r.action === 'carrier' ? r.carrier_connection_id : null, is_active: r.is_active,
    conditions: {
      delivery_zone_ids: r.zones,
      is_fragile: r.fragile === '' ? null : r.fragile === 'true',
      min_amount: r.min_amount === '' ? null : Number(r.min_amount),
      max_amount: r.max_amount === '' ? null : Number(r.max_amount),
      max_weight_g: r.max_weight_kg === '' ? null : Math.round(Number(r.max_weight_kg) * 1000),
    },
  }
  try {
    r.id ? await http.put(`/shipping-rules/${r.id}`, payload) : await http.post('/shipping-rules', payload)
    editing.value = null
    await load()
  } catch (e) {
    errors.value = fieldErrors(e)
  }
}

async function remove() {
  await http.delete(`/shipping-rules/${editing.value.id}`)
  editing.value = null
  await load()
}

function describe(rule) {
  const c = rule.conditions ?? {}
  const parts = []
  if (c.delivery_zone_ids?.length) parts.push(`va à ${data.value.zones.filter((z) => c.delivery_zone_ids.includes(z.id)).map((z) => z.name).join(' ou ')}`)
  if (c.is_fragile === true) parts.push('contient un produit fragile')
  if (c.is_fragile === false) parts.push('ne contient rien de fragile')
  if (c.min_amount) parts.push(`fait au moins ${money(c.min_amount)}`)
  if (c.max_amount) parts.push(`fait au plus ${money(c.max_amount)}`)
  if (c.max_weight_g) parts.push(`pèse au plus ${c.max_weight_g / 1000} kg`)
  return parts.length ? `Si la commande ${parts.join(' et ')}` : 'Pour toutes les commandes'
}
</script>

<template>
  <div class="space-y-5">
    <DeliveryTabs />
    <Alert v-if="error">{{ error }}</Alert>

    <template v-if="data">
      <section class="card space-y-3 p-4">
        <h3 class="font-semibold">Quand une commande est confirmée, qui la livre ?</h3>
        <div class="grid gap-2 sm:grid-cols-3">
          <label v-for="[value, label, help, recommended] in MODES" :key="value" class="flex cursor-pointer gap-2 rounded-lg border p-3 text-sm"
                 :class="data.assignment_mode === value ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200'">
            <input type="radio" :checked="data.assignment_mode === value" class="mt-0.5 accent-emerald-700" @change="settings({ assignment_mode: value })" />
            <span>
              <span class="block font-medium">{{ label }}<span v-if="recommended" class="ml-1.5 rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] font-semibold text-emerald-800">conseillé</span></span>
              <span class="text-slate-500">{{ help }}</span>
            </span>
          </label>
        </div>

        <div v-if="data.assignment_mode !== 'off'" class="rounded-lg bg-slate-50 p-3 text-sm">
          <p class="mb-1.5 font-medium">Comment le livreur est choisi</p>
          <ol class="list-decimal space-y-1 pl-5 text-slate-600">
            <li>Si un <strong>cas particulier</strong> ci-dessous correspond à la commande, c'est lui qui décide.</li>
            <li>Sinon, <strong>un de vos livreurs</strong> qui couvre le lieu de livraison et qui est disponible : celui du quartier d'abord, puis le moins occupé. Un livreur sans zone cochée couvre tous les lieux.</li>
            <li>S'il n'y en a pas, <strong>l'entreprise de livraison la moins chère</strong> parmi celles que vous avez ajoutées.</li>
            <li v-if="data.assignment_mode === 'auto'">Si personne ne convient, rien ne part : la commande reste dans <strong>« À faire livrer »</strong> et la raison est notée dans son historique.</li>
          </ol>
          <p class="mt-2 text-slate-500">Un livreur choisi à la saisie de la commande passe toujours avant. Un livreur qui n'a pas encore ouvert son lien d'accès n'est jamais choisi tout seul.</p>
        </div>
      </section>

      <section class="card p-4">
        <div class="mb-1 flex items-center">
          <h3 class="font-semibold">Cas particuliers <span class="font-normal text-slate-500">(facultatif)</span></h3>
          <button class="btn-secondary ml-auto px-3 py-1.5" @click="open()">+ Cas</button>
        </div>
        <p class="mb-3 text-sm text-slate-500">Pour certaines commandes, imposer qui livre. Les cas sont vérifiés de haut en bas : le premier qui correspond décide.</p>
        <ol class="divide-y divide-slate-100 text-sm">
          <li v-for="(r, i) in data.data" :key="r.id" class="flex items-center gap-3 py-2" :class="{ 'opacity-50': !r.is_active }">
            <span class="w-6 text-center text-slate-400">{{ i + 1 }}</span>
            <button class="min-w-0 flex-1 text-left hover:text-emerald-700" @click="open(r)">
              <span class="block font-medium">{{ r.name }}<span v-if="!r.is_active" class="ml-1.5 text-xs font-normal text-slate-500">(en pause)</span></span>
              <span class="block text-slate-500">{{ describe(r) }} → <strong>{{ r.action === 'carrier' ? r.carrier : r.action_label }}</strong></span>
            </button>
            <button class="btn-ghost px-2" :disabled="i === 0" aria-label="Monter" @click="move(i, -1)">↑</button>
            <button class="btn-ghost px-2" :disabled="i === data.data.length - 1" aria-label="Descendre" @click="move(i, 1)">↓</button>
          </li>
        </ol>
        <div v-if="!data.data.length">
          <p class="py-1 text-sm text-slate-500">Aucun cas particulier : le choix suit les étapes ci-dessus. Exemples à ajouter en un clic :</p>
          <div class="mt-2 flex flex-wrap gap-2">
            <button v-for="t in TEMPLATES" :key="t.name" class="rounded-full border border-slate-200 px-3 py-1 text-sm hover:border-emerald-600 hover:text-emerald-700" @click="open(null, t)">{{ t.text }}</button>
          </div>
        </div>
      </section>
    </template>

    <Modal v-if="editing" :title="editing.id ? 'Modifier le cas particulier' : 'Nouveau cas particulier'" @close="editing = null">
      <form class="space-y-4" @submit.prevent="save">
        <Field id="rule-name" label="Nom" :error="errors.name"><input id="rule-name" v-model="editing.name" class="input" maxlength="120" required placeholder="ex. Colis fragiles" /></Field>
        <fieldset class="space-y-2 rounded-lg border border-slate-200 p-3 text-sm">
          <legend class="px-1 font-medium">Si la commande…</legend>
          <div>
            <p class="mb-1 text-slate-600">Va dans un de ces lieux <span class="text-slate-400">(aucun coché : partout)</span></p>
            <div class="flex flex-wrap gap-2">
              <label v-for="z in data.zones" :key="z.id" class="flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1">
                <input v-model="editing.zones" type="checkbox" :value="z.id" class="accent-emerald-700" /> {{ z.name }}
              </label>
            </div>
            <p class="mt-1 text-xs text-slate-400">Seuls les lieux qui ont un prix à part dans Tarifs et zones apparaissent ici.</p>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <Field id="rule-fragile" label="Contient un produit fragile">
              <select id="rule-fragile" v-model="editing.fragile" class="input"><option value="">Peu importe</option><option value="true">Oui</option><option value="false">Non</option></select>
            </Field>
            <Field id="rule-weight" label="Pèse au plus (kg)"><input id="rule-weight" v-model="editing.max_weight_kg" type="number" min="0" step="0.1" class="input" /></Field>
            <Field id="rule-min" label="Fait au moins (F)"><input id="rule-min" v-model="editing.min_amount" type="number" min="0" class="input" /></Field>
            <Field id="rule-max" label="Fait au plus (F)"><input id="rule-max" v-model="editing.max_amount" type="number" min="0" class="input" /></Field>
          </div>
        </fieldset>
        <Field id="rule-action" label="… alors elle est livrée par" :error="errors.action">
          <select id="rule-action" v-model="editing.action" class="input">
            <option value="own_courier">Un de mes livreurs (qui couvre le lieu et disponible)</option>
            <option value="carrier">Une entreprise de livraison précise</option>
            <option value="cheapest">Le moins cher</option>
            <option value="fastest">Le plus rapide (d'après l'historique)</option>
            <option value="manual">Je choisis moi-même (rien n'est envoyé tout seul)</option>
          </select>
        </Field>
        <Field v-if="editing.action === 'carrier'" id="rule-carrier" label="Entreprise de livraison" :error="errors.carrier_connection_id">
          <select id="rule-carrier" v-model="editing.carrier_connection_id" class="input" required>
            <option v-for="c in data.carriers" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </Field>
        <label class="flex items-center gap-2 text-sm"><input v-model="editing.is_active" type="checkbox" class="h-4 w-4 accent-emerald-700" /> En service (décocher pour le mettre en pause)</label>
        <div class="flex gap-2">
          <button v-if="editing.id" type="button" class="btn-ghost text-red-600" @click="remove">Supprimer</button>
          <button class="btn-brand ml-auto">Enregistrer</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
