<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'

// Acompte automatique de la boutique (avec ou sans paiement en ligne)
const settings = ref(null)
const errors = ref({})
const error = ref('')
const saved = ref(false)
const saving = ref(false)

onMounted(async () => {
  try {
    settings.value = (await http.get('/deposit-settings')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
})

async function save() {
  saving.value = true
  saved.value = false
  errors.value = {}
  error.value = ''
  try {
    const s = settings.value
    settings.value = (await http.put('/deposit-settings', {
      deposit_mode: s.deposit_mode,
      deposit_min_total: s.deposit_mode === 'above' ? Number(s.deposit_min_total) || null : null,
      deposit_rule_percent: s.deposit_mode === 'never' ? null : Number(s.deposit_rule_percent) || null,
      deposit_blocks: Boolean(s.deposit_blocks),
    })).data.data
    saved.value = true
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <p class="text-sm text-slate-600">Demandez une partie du prix avant de livrer : le client paie l'acompte (lien de paiement, ou votre Wave avec la capture du reçu), le reste à la livraison. L'acompte d'un article se règle sur sa fiche.</p>
    <Alert v-if="error">{{ error }}</Alert>
    <template v-if="settings">
      <section class="card space-y-2 p-4">
        <h2 class="font-semibold">Acompte automatique</h2>
        <label class="flex items-start gap-2 rounded-lg border p-3 text-sm" :class="settings.deposit_mode === 'never' ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200'">
          <input v-model="settings.deposit_mode" type="radio" value="never" class="mt-0.5" />
          <span><b>Jamais</b><br /><span class="text-slate-500">Seulement les articles qui ont un acompte sur leur fiche.</span></span>
        </label>
        <label class="flex items-start gap-2 rounded-lg border p-3 text-sm" :class="settings.deposit_mode === 'above' ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200'">
          <input v-model="settings.deposit_mode" type="radio" value="above" class="mt-0.5" />
          <span class="flex flex-wrap items-center gap-1.5"><b class="w-full">Au-dessus d'un montant</b>
            Commande de plus de <input v-model="settings.deposit_min_total" type="number" min="1" class="input w-28 py-1" aria-label="Montant" /> F → acompte de
            <input v-model="settings.deposit_rule_percent" type="number" min="5" max="100" class="input w-20 py-1" aria-label="Pourcentage" /> %</span>
        </label>
        <label class="flex items-start gap-2 rounded-lg border p-3 text-sm" :class="settings.deposit_mode === 'always' ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200'">
          <input v-model="settings.deposit_mode" type="radio" value="always" class="mt-0.5" />
          <span class="flex flex-wrap items-center gap-1.5"><b class="w-full">Toutes les commandes</b>
            Acompte de <input v-model="settings.deposit_rule_percent" type="number" min="5" max="100" class="input w-20 py-1" aria-label="Pourcentage" /> %</span>
        </label>
        <p v-if="errors.deposit_min_total || errors.deposit_rule_percent" class="text-sm text-red-700">{{ errors.deposit_min_total || errors.deposit_rule_percent }}</p>
        <p class="text-xs text-slate-500">Avec un acompte sur un article et cette règle, le plus grand des deux est demandé. Vous pouvez toujours le changer sur une commande.</p>
      </section>
      <section class="card space-y-2 p-4">
        <h2 class="font-semibold">Tant que l'acompte n'est pas payé</h2>
        <label class="flex items-start gap-2 text-sm"><input v-model="settings.deposit_blocks" type="radio" :value="true" class="mt-0.5" /> <span><b>La commande attend</b><br /><span class="text-slate-500">« Acompte attendu » : elle n'est pas confiée au livreur.</span></span></label>
        <label class="flex items-start gap-2 text-sm"><input v-model="settings.deposit_blocks" type="radio" :value="false" class="mt-0.5" /> <span><b>Juste un rappel</b><br /><span class="text-slate-500">La commande peut partir quand même.</span></span></label>
        <p class="text-xs text-slate-500">Dans tous les cas, le client reçoit un rappel sur WhatsApp s'il n'a pas payé 20 h après sa commande.</p>
      </section>
      <div class="flex items-center gap-3">
        <RouterLink to="/gestion/catalogue" class="text-sm font-medium text-emerald-700 underline">Acompte d'un article : sur sa fiche</RouterLink>
        <span v-if="saved" class="ml-auto text-sm text-emerald-700">Enregistré ✓</span>
        <button class="btn-brand" :class="{ 'ml-auto': !saved }" :disabled="saving" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </div>
    </template>
  </div>
</template>
