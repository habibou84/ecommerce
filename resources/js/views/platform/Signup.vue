<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import AuthShell from '../../components/AuthShell.vue'
import Alert from '../../components/Alert.vue'
import CodeStep from '../../components/CodeStep.vue'
import Field from '../../components/Field.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { boot } from '../../lib/boot'
import { slugify } from '../../lib/format'

const form = reactive({ store_name: '', slug: '', name: '', phone: '', password: '', partner: null })
// Invitation d'une entreprise de livraison de l'annuaire (lien « ?partenaire= ») : un mois d'essai en plus
const partner = ref(null)

onMounted(async () => {
  const slug = new URLSearchParams(window.location.search).get('partenaire')
  if (!slug) return
  try {
    const { data } = await http.get('/signup/partner', { params: { slug } })
    partner.value = data.data
    form.partner = data.data?.slug ?? null
  } catch {
    // Annuaire injoignable : inscription avec l'essai habituel
  }
})
const slugTouched = ref(false)
const slugState = reactive({ checking: false, available: null, message: '' })
const errors = ref({})
const error = ref('')
const busy = ref(false)
const pending = ref(null) // vérification du numéro en cours

// L'adresse suit le nom de la boutique tant que la personne ne l'a pas modifiée
watch(() => form.store_name, (name) => {
  if (!slugTouched.value) form.slug = slugify(name)
})

let slugTimer
watch(() => form.slug, (slug) => {
  clearTimeout(slugTimer)
  slugState.available = null
  slugState.message = ''
  if (slug.length < 3) return
  slugState.checking = true
  slugTimer = setTimeout(async () => {
    try {
      const { data } = await http.get('/signup/slug', { params: { slug } })
      if (data.slug !== form.slug) return
      slugState.available = data.available
      slugState.message = data.message ?? ''
    } finally {
      slugState.checking = false
    }
  }, 350)
})

const preview = computed(() => `${form.slug || 'votre-boutique'}.${boot.domain}`)

async function submit() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    const { data, status } = await http.post('/signup', form)
    if (status === 201) {
      window.location.assign(data.redirect)
      return
    }
    pending.value = data
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

function done(data) {
  window.location.assign(data.redirect)
}
</script>

<template>
  <AuthShell
    :title="pending ? 'Vérifiez votre numéro' : 'Créez votre espace marchand'"
    :subtitle="pending ? '' : `${partner?.trial ?? boot.trialOffer} offert, sans carte bancaire ni engagement. Tout se fait depuis votre téléphone.`"
  >
    <CodeStep v-if="pending" :verification="pending.verification" :demo-code="pending.demo_code" submit-label="Créer mon espace marchand" @done="done" />

    <form v-else class="space-y-4" @submit.prevent="submit">
      <Alert v-if="error">{{ error }}</Alert>
      <div v-if="partner" class="flex items-center gap-3 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-900 ring-1 ring-emerald-200">
        <img v-if="partner.logo_url" :src="partner.logo_url" alt="" class="h-10 w-10 shrink-0 rounded-lg bg-white object-contain" />
        <p>Invité par <strong>{{ partner.name }}</strong> : <strong>{{ partner.trial }}</strong> offerts pour bien démarrer.</p>
      </div>

      <Field id="store_name" label="Nom de la boutique" :error="errors.store_name">
        <input id="store_name" v-model="form.store_name" class="input" maxlength="80" placeholder="Awa Mode" required autofocus />
      </Field>

      <Field id="slug" label="Adresse de votre boutique" :error="errors.slug || (slugState.available === false ? slugState.message : '')">
        <div class="flex items-stretch overflow-hidden rounded-lg border border-slate-300 focus-within:border-emerald-600 focus-within:ring-2 focus-within:ring-emerald-600/20">
          <input
            id="slug"
            v-model="form.slug"
            class="min-w-0 flex-1 px-3 py-2.5 text-base outline-none sm:text-sm"
            maxlength="40"
            autocapitalize="off"
            spellcheck="false"
            required
            @input="slugTouched = true; form.slug = form.slug.toLowerCase()"
          />
          <span class="flex items-center bg-slate-50 px-3 text-sm text-slate-500">.{{ boot.domain }}</span>
        </div>
        <p v-if="!errors.slug && slugState.available !== false" class="mt-1 text-sm" :class="slugState.available ? 'text-emerald-700' : 'text-slate-500'">
          <template v-if="slugState.checking">Vérification…</template>
          <template v-else-if="slugState.available">✓ {{ preview }} est disponible</template>
          <template v-else>Votre site et votre gestion : {{ preview }}</template>
        </p>
      </Field>

      <Field id="name" label="Votre nom" :error="errors.name">
        <input id="name" v-model="form.name" class="input" maxlength="80" autocomplete="name" required />
      </Field>

      <Field id="phone" label="Votre numéro de téléphone" :error="errors.phone" hint="Il sert à vous connecter. Vous le vérifierez par WhatsApp : un message à envoyer, rien à recopier.">
        <input id="phone" v-model="form.phone" class="input" type="tel" inputmode="tel" autocomplete="tel" placeholder="07 07 07 07 07" required />
      </Field>

      <Field id="password" label="Mot de passe" :error="errors.password" hint="8 caractères au moins.">
        <input id="password" v-model="form.password" class="input" type="password" autocomplete="new-password" minlength="8" required />
      </Field>

      <p class="text-xs text-slate-500">En créant votre espace marchand, vous acceptez les <a href="/cgu" target="_blank" class="underline">conditions d'utilisation et de vente</a> et la <a href="/confidentialite" target="_blank" class="underline">politique de confidentialité</a>.</p>
      <button class="btn-brand w-full" :disabled="busy || slugState.available === false">
        {{ busy ? 'Un instant…' : 'Continuer' }}
      </button>
    </form>

    <template #footer>
      Vous avez déjà un espace marchand ? <RouterLink to="/connexion" class="font-medium text-emerald-700 hover:underline">Se connecter</RouterLink>
    </template>
  </AuthShell>
</template>
