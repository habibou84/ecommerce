<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AuthShell from '../../components/AuthShell.vue'
import Alert from '../../components/Alert.vue'
import Field from '../../components/Field.vue'
import http, { errorMessage } from '../../lib/http'

// Carte à QR code « clé d'accès » : ce qu'elle donne, puis créer sa boutique avec ou l'activer sur la sienne.
// Carte Membre Premium offerte par une entreprise de livraison : la boutique créée est parrainée par elle.
const props = defineProps({ code: { type: String, default: '' } })
const router = useRouter()
const typed = ref(props.code)
const key = ref(null)
const error = ref('')
const busy = ref(false)

async function check() {
  if (!typed.value.trim()) return
  busy.value = true
  error.value = ''
  key.value = null
  try {
    key.value = (await http.get(`/access-keys/${encodeURIComponent(typed.value.trim())}`)).data.data
    if (props.code !== key.value.code) router.replace(`/cle/${key.value.code}`)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

onMounted(check)

const percent = (rate) => `${Math.round((rate ?? 0) * 100)} %`
const offer = (k) => {
  if (k.offered_by) return `Offre ${k.plan} pendant ${k.months} mois, puis −${percent(k.discount_rate)}`
  return `Offre ${k.plan} ${k.founder ? 'à vie' : `pendant ${k.months} mois`}`
}
const signupUrl = (k) => `/creer-ma-boutique?cle=${k.code}${k.offered_by ? `&partenaire=${encodeURIComponent(k.offered_by.slug)}` : ''}`
</script>

<template>
  <AuthShell :title="key?.premium ? 'Carte Membre Premium Jibiat Marchand' : 'Clé d\'accès Jibiat Marchand'" subtitle="Gérez votre boutique, vos commandes, vos livraisons et vos ventes en live depuis votre téléphone.">
    <form v-if="!key" class="space-y-4" @submit.prevent="check">
      <Alert v-if="error">{{ error }}</Alert>
      <Field id="code" label="Code écrit sur la carte">
        <input id="code" v-model="typed" class="input font-mono uppercase tracking-widest" placeholder="ABCD-EFGH" maxlength="20" autocapitalize="characters" spellcheck="false" required autofocus />
      </Field>
      <button class="btn-brand w-full" :disabled="busy">{{ busy ? 'Un instant…' : 'Voir ma clé' }}</button>
    </form>

    <div v-else class="space-y-5">
      <div class="rounded-2xl p-5 text-center" :class="key.premium ? 'bg-slate-950 text-white' : 'bg-emerald-50 text-emerald-950 ring-1 ring-emerald-200'">
        <p class="text-xs font-semibold tracking-widest" :class="key.premium ? 'text-amber-200' : 'text-emerald-700'">JIBIAT MARCHAND</p>
        <p v-if="key.premium" class="mt-2 text-2xl font-bold text-amber-300">{{ key.badge }}</p>
        <p v-if="key.offered_by" class="mt-1 text-sm text-slate-300">Offerte par <strong class="text-white">{{ key.offered_by.name }}</strong></p>
        <p class="mt-2 text-lg font-semibold">{{ offer(key) }}</p>
        <p class="mt-2 font-mono tracking-widest">{{ key.code }}</p>
      </div>

      <template v-if="key.available">
        <ul class="space-y-1.5 text-sm text-slate-700">
          <li>✓ Toutes les fonctions de l'offre {{ key.plan }}, sans rien payer{{ key.founder ? '' : ` pendant ${key.months} mois` }}</li>
          <li v-if="key.founder">✓ Votre carte reste active tant que votre boutique vit : une commande au moins tous les 2 mois</li>
          <li v-if="key.offered_by">✓ Ensuite, −{{ percent(key.discount_rate) }} sur votre abonnement tant que vous livrez avec {{ key.offered_by.name }}</li>
          <li>✓ L'assistant IA se recharge à part, si vous le voulez</li>
          <li>✓ Une seule boutique par carte</li>
        </ul>
        <a :href="signupUrl(key)" class="btn-brand w-full">Créer ma boutique avec cette clé</a>
        <a :href="`/connexion?cle=${key.code}`" class="btn-ghost w-full">J'ai déjà une boutique : l'activer</a>
      </template>
      <Alert v-else tone="warning">Cette clé a déjà été utilisée par une boutique.</Alert>
    </div>
  </AuthShell>
</template>
