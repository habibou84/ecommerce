<script setup>
import { computed, reactive, ref, watch } from 'vue'
import Alert from './Alert.vue'
import ColorIcon from './ColorIcon.vue'
import Icon from './Icon.vue'
import ToggleSwitch from './ToggleSwitch.vue'
import http, { errorMessage, fieldErrors } from '../lib/http'

/**
 * Réponses automatiques de l'assistant (désactivées par défaut) : un interrupteur, quand répondre, combien au plus.
 * Une seule mise en garde à la fois ; les garde-fous sont rangés dans « Comment ça marche ».
 */
const props = defineProps({ data: { type: Object, required: true } })
const emit = defineEmits(['update'])

const form = reactive({ enabled: false, when: 'always', from: '19:00', to: '08:00', per_hour: 15 })
const busy = ref(false)
const saved = ref(false)
const error = ref('')
const errors = ref({})
watch(() => props.data.auto_replies, (v) => Object.assign(form, v), { immediate: true })
// Modifié mais pas encore enregistré : la barre « Enregistrer » reste visible sur mobile
const dirty = computed(() => Object.keys(form).some((k) => form[k] !== props.data.auto_replies[k]))

async function save() {
  if (form.enabled && !props.data.auto_replies.enabled && props.data.channel === 'linked'
    && !window.confirm('Les réponses partiront de votre WhatsApp relié par QR code, une connexion non officielle. WhatsApp peut bloquer un numéro qui ressemble à un robot. Les garde-fous limitent ce risque sans le supprimer. Activer quand même ?')) {
    form.enabled = false
    return
  }
  busy.value = true
  error.value = ''
  errors.value = {}
  saved.value = false
  try {
    emit('update', (await http.put('/whatsapp/sales', form)).data.data)
    saved.value = true
  } catch (e) {
    errors.value = fieldErrors(e)
    error.value = Object.keys(errors.value).length ? '' : errorMessage(e)
  } finally {
    busy.value = false
  }
}

// La mise en garde qui compte le plus, une seule
function warning() {
  if (props.data.channel === null) return 'Connectez d\'abord votre numéro WhatsApp officiel (onglet Messages automatiques).'
  if (!props.data.assistant.enabled) return 'L\'assistant n\'est pas encore activé sur la plateforme.'
  if (props.data.channel === 'linked') return 'Les réponses partiront de votre WhatsApp relié par QR code : gardez un plafond bas.'
  return ''
}
</script>

<template>
  <form class="card space-y-4 p-4 sm:p-5" @submit.prevent="save">
    <div class="flex items-start gap-3">
      <ColorIcon name="aide" :size="44" />
      <div class="min-w-0 flex-1">
        <h3 class="text-[17px] font-extrabold">Répondre aux questions simples</h3>
        <p class="text-sm text-slate-600">Disponible ? Prix ? Tailles ? Frais de livraison ? L'assistant répond avec votre catalogue et votre stock. En cas de doute, il vous laisse la main.</p>
      </div>
      <ToggleSwitch v-model="form.enabled" label="Répondre automatiquement aux questions simples" class="mt-1" />
    </div>

    <fieldset class="grid gap-3 sm:grid-cols-2" :disabled="!form.enabled" :class="form.enabled ? '' : 'opacity-60'">
      <div class="rounded-xl border border-slate-200 p-3 text-sm">
        <p class="font-medium">Quand répondre ?</p>
        <div class="mt-2 inline-flex rounded-lg bg-slate-100 p-1" role="radiogroup" aria-label="Quand répondre">
          <button type="button" role="radio" :aria-checked="form.when === 'always'" class="rounded-md px-3 py-1.5" :class="form.when === 'always' ? 'bg-white font-medium shadow-sm' : 'text-slate-600'" @click="form.when = 'always'">Toujours</button>
          <button type="button" role="radio" :aria-checked="form.when === 'away'" class="rounded-md px-3 py-1.5" :class="form.when === 'away' ? 'bg-white font-medium shadow-sm' : 'text-slate-600'" @click="form.when = 'away'">À certaines heures</button>
        </div>
        <p v-if="form.when === 'away'" class="mt-2 flex items-center gap-2">
          De <input v-model="form.from" type="time" lang="fr" class="input w-28 py-1" aria-label="Heure de début" />
          à <input v-model="form.to" type="time" lang="fr" class="input w-28 py-1" aria-label="Heure de fin" />
        </p>
        <p v-if="form.when === 'away'" class="mt-1 text-xs text-slate-500">Par exemple la nuit, quand personne ne répond.</p>
        <p v-if="errors.to || errors.from" class="mt-1 text-xs text-red-600">{{ errors.to ?? errors.from }}</p>
      </div>
      <div class="rounded-xl border border-slate-200 p-3 text-sm">
        <p class="font-medium">Combien au plus ?</p>
        <p class="mt-2 flex items-center gap-2"><input v-model.number="form.per_hour" type="number" min="1" max="60" class="input w-20 py-1 text-center" aria-label="Réponses par heure" /> réponses par heure</p>
        <p v-if="errors.per_hour" class="mt-1 text-xs text-red-600">{{ errors.per_hour }}</p>
        <p class="mt-2 text-xs text-slate-500">{{ data.sent_last_7_days }} réponse(s) automatique(s) ces 7 derniers jours</p>
      </div>
    </fieldset>

    <p v-if="warning()" class="flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900"><Icon name="alert" :size="16" class="mt-0.5 shrink-0" />{{ warning() }}</p>
    <p v-if="data.assistant.enabled && data.assistant.limit === 0" class="text-xs text-slate-500">Assistant : {{ data.assistant.credits }} actions en réserve. Rechargez dans Réglages › Abonnement.</p>
    <p v-else-if="data.assistant.enabled && data.assistant.limit !== null" class="text-xs text-slate-500">
      Assistant ce mois-ci : {{ data.assistant.used }} / {{ data.assistant.limit }} actions<template v-if="data.assistant.credits"> (+ {{ data.assistant.credits }} en réserve)</template>.
    </p>

    <details class="text-sm">
      <summary class="cursor-pointer font-medium text-emerald-700">Comment ça marche</summary>
      <ul class="mt-2 list-disc space-y-1 pl-5 text-slate-600">
        <li>Réponse environ 45 secondes après le dernier message du client, avec « en train d'écrire ».</li>
        <li>Rien pendant 30 minutes après une réponse de votre équipe.</li>
        <li>Au plus 6 réponses automatiques par client et par jour, et le plafond par heure ci-dessus.</li>
        <li>Pas de réponse en cas de doute (réclamation, négociation, information manquante) : vous recevez la notification habituelle.</li>
        <li>Chaque réponse compte une action de l'assistant.</li>
      </ul>
    </details>
    <Alert v-if="error">{{ error }}</Alert>

    <!-- Enregistrer : barre fixe sur mobile, au-dessus de la barre du bas -->
    <div class="flex items-center gap-3 sm:static sm:border-0 sm:bg-transparent sm:p-0" :class="dirty ? 'fixed inset-x-0 bottom-[76px] z-20 border-t border-slate-200 bg-white/95 p-3 backdrop-blur' : ''">
      <button class="btn-brand flex-1 sm:flex-none" :disabled="busy">{{ busy ? 'Enregistrement…' : 'Enregistrer' }}</button>
      <span v-if="saved" class="text-sm text-emerald-700">Enregistré ✓</span>
    </div>
  </form>
</template>
