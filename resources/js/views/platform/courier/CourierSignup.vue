<script setup>
// Inscription d'un livreur au lien commun : sans boutique. Numéro WhatsApp vérifié, puis son espace s'ouvre.
import { computed, onMounted, reactive, ref } from 'vue'
import AuthShell from '../../../components/AuthShell.vue'
import Alert from '../../../components/Alert.vue'
import CodeStep from '../../../components/CodeStep.vue'
import Field from '../../../components/Field.vue'
import PasswordInput from '../../../components/PasswordInput.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'

const form = reactive({ name: '', phone: '', password: '', vehicle_type: 'moto', place_ids: [], listed: true })
const places = ref([])
const vehicles = ref({})
const errors = ref({})
const error = ref('')
const busy = ref(false)
const pending = ref(null)

onMounted(async () => {
  const { data } = await http.get('/courier-space/places')
  places.value = data.data
  vehicles.value = data.vehicles
})
const communes = computed(() => places.value.filter((p) => p.type === 'commune'))
function toggle(id) {
  form.place_ids = form.place_ids.includes(id) ? form.place_ids.filter((x) => x !== id) : [...form.place_ids, id]
}

const done = (data) => window.location.assign(data.redirect)

async function submit() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    pending.value = (await http.post('/courier-space/signup', form)).data
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthShell :title="pending ? 'Vérifiez votre numéro' : 'Devenir livreur'" :subtitle="pending ? '' : 'Inscrivez-vous une fois, livrez pour plusieurs boutiques.'">
    <Alert v-if="error" class="mb-4">{{ error }}</Alert>
    <CodeStep v-if="pending" :verification="pending.verification" :demo-code="pending.demo_code" submit-label="Créer mon espace livreur" @done="done" />
    <form v-else class="space-y-4" @submit.prevent="submit">
      <Field id="c-name" label="Nom complet" :error="errors.name">
        <input id="c-name" v-model="form.name" class="input" autocomplete="name" maxlength="80" required />
      </Field>
      <Field id="c-phone" label="Numéro WhatsApp" :error="errors.phone">
        <input id="c-phone" v-model="form.phone" class="input" type="tel" inputmode="tel" autocomplete="tel" required />
      </Field>
      <Field id="c-password" label="Mot de passe (8 caractères ou plus)" :error="errors.password">
        <PasswordInput id="c-password" v-model="form.password" class="input" autocomplete="new-password" required />
      </Field>
      <div>
        <p class="label">Véhicule</p>
        <div class="flex flex-wrap gap-2">
          <button v-for="(label, key) in vehicles" :key="key" type="button" class="rounded-full border px-3 py-1.5 text-sm"
                  :class="form.vehicle_type === key ? 'border-emerald-600 bg-emerald-50 font-medium' : 'border-field'" @click="form.vehicle_type = key">{{ label }}</button>
        </div>
      </div>
      <div>
        <p class="label">Où livrez-vous ?</p>
        <div class="flex flex-wrap gap-2">
          <button v-for="c in communes" :key="c.id" type="button" class="rounded-full border px-3 py-1.5 text-sm"
                  :class="form.place_ids.includes(c.id) ? 'border-emerald-600 bg-emerald-50 font-medium' : 'border-field'" @click="toggle(c.id)">{{ c.name }}</button>
        </div>
        <p class="explain-detail mt-1">Les quartiers s'ajoutent ensuite dans votre profil.</p>
      </div>
      <label class="flex items-start gap-2 text-sm">
        <input v-model="form.listed" type="checkbox" class="mt-0.5 h-4 w-4 accent-emerald-700" />
        <span>Apparaître dans l'annuaire des livreurs <span class="explain-detail block">Votre numéro reste caché.</span></span>
      </label>
      <button class="btn-brand w-full" :disabled="busy">{{ busy ? 'Un instant…' : 'Recevoir le code sur WhatsApp' }}</button>
    </form>
    <template #footer>
      Déjà inscrit ? <RouterLink to="/livreur/connexion" class="font-medium text-emerald-700 hover:underline">Se connecter</RouterLink>
    </template>
  </AuthShell>
</template>
