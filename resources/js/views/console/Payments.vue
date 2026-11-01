<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'
import { formatDateTime, money } from '../../lib/format'
import { useConsoleStore } from '../../stores/console'

const console_ = useConsoleStore()
const pending = ref(null)
const settings = ref(null)
const form = reactive({ provider: 'wave_link', wave_link: '', validator_email: '', validator_phone: '', validator_pin: '' })
const error = ref('')
const saved = ref(false)
const busy = ref(null)
const CHANNELS = { mail: 'e-mail', sms: 'SMS', journal: 'SMS non envoyé (pas de fournisseur)' }
const mail = reactive({ host: '', port: 587, username: '', password: '', from_address: '' })
const google = reactive({ client_id: '', client_secret: '' })
const gmailNotice = ref(null)
const mailSaved = ref(false)
const testResult = ref(null)
const testing = ref(false)

function fill(data) {
  settings.value = data
  google.client_id = data.mail.gmail?.client_id ?? ''
  google.client_secret = ''
  Object.assign(mail, { host: data.mail.host ?? '', port: Number(data.mail.port ?? 587), username: data.mail.username ?? '', password: '', from_address: data.mail.from_address ?? '' })
  Object.assign(form, { provider: data.provider, wave_link: data.wave_link ?? '', validator_email: data.validator_email ?? '', validator_phone: data.validator_phone ?? '', validator_pin: '' })
}

async function loadPending() {
  pending.value = (await http.get('/console/payments/pending')).data
  console_.pendingPayments = pending.value.data.length
}

// Retour de Google après « Se connecter avec Google »
const GMAIL_RETURNS = {
  ok: ['success', 'Gmail est connecté : les e-mails partent maintenant par votre compte Gmail.'],
  annule: ['warning', 'Connexion à Google annulée.'],
  expire: ['warning', 'La demande a expiré : recommencez « Se connecter avec Google ».'],
  erreur: ['error', 'Google a refusé la connexion.'],
}
onMounted(async () => {
  const back = new URLSearchParams(window.location.search)
  if (GMAIL_RETURNS[back.get('gmail')]) {
    const [tone, text] = GMAIL_RETURNS[back.get('gmail')]
    gmailNotice.value = { tone, text: back.get('detail') ? `${text} ${back.get('detail')}` : text }
    window.history.replaceState(null, '', window.location.pathname)
  }
  try {
    fill((await http.get('/console/payments/settings')).data.data)
    await loadPending()
  } catch (e) {
    error.value = errorMessage(e)
  }
})

async function decide(invoice, decision) {
  const text = decision === 'received'
    ? `Confirmer la réception de ${money(invoice.amount)} depuis le ${invoice.payer_phone} ? L'offre sera activée.`
    : `Marquer le paiement de ${money(invoice.amount)} comme non reçu ? L'e-commerçant sera prévenu.`
  if (!window.confirm(text)) return
  busy.value = invoice.id
  error.value = ''
  try {
    await http.post(`/console/payments/${invoice.id}/decide`, { decision })
    await loadPending()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = null
  }
}

async function save() {
  error.value = ''
  saved.value = false
  try {
    fill((await http.put('/console/payments/settings', form)).data.data)
    saved.value = true
  } catch (e) {
    error.value = errorMessage(e)
  }
}

// Gmail connecté avec Google (recommandé par Google, sans mot de passe d'application)
const gmail = computed(() => settings.value?.mail.gmail ?? {})
async function saveGoogleClient() {
  error.value = ''
  try {
    fill((await http.put('/console/payments/gmail/client', google)).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  }
}
async function connectGmail() {
  error.value = ''
  try {
    window.location.href = (await http.post('/console/payments/gmail/connect')).data.data.url
  } catch (e) {
    error.value = errorMessage(e)
  }
}
async function disconnectGmail() {
  if (!window.confirm('Déconnecter Gmail ? Les e-mails (alertes, code de connexion) ne partiront plus par ce compte.')) return
  fill((await http.delete('/console/payments/gmail')).data.data)
}
const copied = ref(false)
async function copyRedirect() {
  await navigator.clipboard?.writeText(gmail.value.redirect_uri)
  copied.value = true
}

async function saveMail() {
  error.value = ''
  mailSaved.value = false
  try {
    fill((await http.put('/console/payments/mail', mail)).data.data)
    mailSaved.value = true
  } catch (e) {
    error.value = errorMessage(e)
  }
}

async function testAlert() {
  testing.value = true
  testResult.value = null
  error.value = ''
  try {
    testResult.value = (await http.post('/console/payments/test-alert')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    testing.value = false
  }
}

async function uploadQr(event) {
  const file = event.target.files?.[0]
  if (!file) return
  error.value = ''
  const data = new FormData()
  data.append('image', file)
  try {
    fill((await http.post('/console/payments/wave-qr', data)).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    event.target.value = ''
  }
}

async function removeQr() {
  if (!window.confirm('Retirer le QR code Wave ?')) return
  fill((await http.delete('/console/payments/wave-qr')).data.data)
}
</script>

<template>
  <div class="space-y-5">
    <Alert v-if="error">{{ error }}</Alert>

    <section class="card p-4 text-sm">
      <h3 class="mb-1 text-base font-semibold">Paiements Wave à valider</h3>
      <p class="mb-3 text-slate-600">Vérifiez dans Wave que le montant est arrivé depuis ce numéro, puis confirmez. Vous êtes connecté : pas besoin du code.</p>
      <p v-if="pending && !pending.data.length" class="text-slate-500">Aucun paiement en attente.</p>
      <ul v-else-if="pending" class="divide-y divide-slate-100">
        <li v-for="i in pending.data" :key="i.id" class="flex flex-wrap items-center gap-3 py-3">
          <div class="min-w-0 flex-1">
            <p class="font-medium">
              {{ money(i.amount) }} · {{ i.payer_phone }}
              <span v-if="i.claimed_at" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900">Déclaré payé {{ formatDateTime(i.claimed_at) }}</span>
            </p>
            <p class="text-slate-600"><RouterLink :to="`/console/boutiques/${i.store.id}`" class="text-emerald-800 hover:underline">{{ i.store.name }}</RouterLink><template v-if="i.payer_name"> · {{ i.payer_name }}</template> · {{ i.label }}</p>
            <p class="text-xs text-slate-500">{{ i.number }} · {{ formatDateTime(i.created_at) }}<template v-if="i.alerts.length"> · alerte {{ i.alerts.map((a) => CHANNELS[a.channel] ?? 'non envoyée').join(', ') }}</template></p>
          </div>
          <button class="btn-brand" :disabled="busy === i.id" @click="decide(i, 'received')">Reçu : activer</button>
          <button class="btn-ghost" :disabled="busy === i.id" @click="decide(i, 'missing')">Non reçu</button>
        </li>
      </ul>
    </section>

    <section v-if="settings" class="card space-y-4 p-4 text-sm">
      <h3 class="text-base font-semibold">Paiement des abonnements</h3>
      <!-- Dernier paiement d'abonnement refusé : la raison du prestataire (clés, mode, compte non activé) -->
      <div v-if="settings.last_failure" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
        <p class="font-semibold">Dernier paiement d'abonnement refusé · {{ formatDateTime(settings.last_failure.at) }}<template v-if="settings.last_failure.store"> · {{ settings.last_failure.store }}</template></p>
        <p class="mt-1 break-words">{{ settings.last_failure.error || 'Raison non donnée par le prestataire.' }}</p>
        <p class="mt-1 text-xs text-red-700">Mode du serveur : <b>{{ settings.mode === 'test' ? 'test' : 'réel' }}</b> (BILLING_MODE). Les clés du prestataire doivent être du même mode.</p>
      </div>
      <fieldset class="space-y-2">
        <legend class="mb-1 font-medium">Moyen de paiement actif</legend>
        <label v-for="p in settings.providers" :key="p.code" class="flex items-center gap-2" :class="p.available ? '' : 'text-slate-400'">
          <input v-model="form.provider" type="radio" :value="p.code" :disabled="!p.available" class="accent-emerald-700" />
          {{ p.label }}<span v-if="!p.available" class="text-xs">(clés à installer sur le serveur)</span>
        </label>
      </fieldset>

      <template v-if="form.provider === 'wave_link'">
        <label class="block max-w-xl">Lien de paiement marchand Wave
          <input v-model="form.wave_link" type="url" class="input mt-1" placeholder="https://pay.wave.com/m/…" />
          <span class="text-xs text-slate-500">Le montant est ajouté au lien. Mettez {montant} dans le lien pour choisir sa place.</span>
        </label>
        <div class="space-y-2">
          <p class="font-medium">QR code Wave</p>
          <p class="text-xs text-slate-500">Affiché aux e-commerçants sur ordinateur : ils le scannent avec Wave sur leur téléphone.</p>
          <div class="flex flex-wrap items-center gap-4">
            <img v-if="settings.wave_qr_url" :src="settings.wave_qr_url" alt="QR code Wave" class="h-32 w-32 rounded-lg object-contain ring-1 ring-slate-200" />
            <label class="btn-ghost cursor-pointer">{{ settings.wave_qr_url ? 'Remplacer' : 'Ajouter le QR code' }}<input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="uploadQr" /></label>
            <button v-if="settings.wave_qr_url" class="text-red-700 underline" @click="removeQr">Retirer</button>
          </div>
        </div>
        <div class="grid max-w-xl gap-3 sm:grid-cols-2">
          <label>E-mail du validateur<input v-model="form.validator_email" type="email" class="input mt-1" placeholder="paiements@…" /></label>
          <label>Téléphone du validateur<input v-model="form.validator_phone" type="tel" class="input mt-1" placeholder="07 00 00 00 00" /></label>
        </div>
        <p class="text-xs text-slate-500">
          À chaque paiement, un e-mail part d'abord ; s'il ne part pas (ou sans adresse), un SMS est envoyé. Les paiements apparaissent aussi ici.
        </p>
        <label class="block max-w-xs">Code de validation
          <input v-model="form.validator_pin" type="password" inputmode="numeric" autocomplete="new-password" class="input mt-1" :placeholder="settings.pin_set ? 'Déjà défini : laisser vide pour le garder' : 'Au moins 4 chiffres'" />
          <span class="text-xs text-slate-500">Demandé quand vous validez depuis le lien reçu par e-mail ou SMS.</span>
        </label>
      </template>

      <div class="flex items-center gap-2"><button class="btn-brand" @click="save">Enregistrer</button><span v-if="saved" class="text-emerald-700">Enregistré ✓</span></div>
    </section>

    <section v-if="settings && form.provider === 'wave_link'" class="card space-y-4 p-4 text-sm">
      <h3 class="text-base font-semibold">Envoi des alertes</h3>
      <ul class="space-y-1">
        <li>
          <span :class="settings.mail.source ? 'text-emerald-700' : 'text-amber-800'">{{ settings.mail.source ? '✓' : '✗' }} E-mail :</span>
          {{ settings.mail.source === 'gmail' ? `par Gmail (${settings.mail.gmail.email})` : settings.mail.source === 'console' ? `par ${settings.mail.host} (réglé plus bas)` : settings.mail.source === 'server' ? 'par le serveur' : 'aucun serveur d\'e-mail : les e-mails ne partent pas' }}
        </li>
        <li>
          <span :class="settings.sms_ready ? 'text-emerald-700' : 'text-amber-800'">{{ settings.sms_ready ? '✓' : '✗' }} SMS :</span>
          {{ settings.sms_ready ? 'le serveur envoie les SMS' : 'pas de fournisseur de SMS sur le serveur : les SMS ne partent pas' }}
        </li>
      </ul>

      <div class="space-y-2">
        <button class="btn-ghost" :disabled="testing" @click="testAlert">{{ testing ? 'Envoi…' : 'Envoyer une alerte d\'essai' }}</button>
        <ul v-if="testResult" class="space-y-1">
          <li :class="testResult.mail.ok ? 'text-emerald-700' : 'text-red-700'">{{ testResult.mail.ok ? '✓' : '✗' }} E-mail : {{ testResult.mail.detail }}</li>
          <li :class="testResult.sms.ok ? 'text-emerald-700' : 'text-red-700'">{{ testResult.sms.ok ? '✓' : '✗' }} SMS : {{ testResult.sms.detail }}</li>
        </ul>
      </div>
    </section>

    <!-- Envoi des e-mails : alertes de paiement et code de connexion à la console -->
    <section v-if="settings" class="card space-y-4 p-4 text-sm">
      <div>
        <h3 class="text-base font-semibold">Envoi des e-mails</h3>
        <p class="text-slate-600">Alertes de paiement et code de connexion à la console partent de votre compte Gmail.</p>
      </div>
      <Alert v-if="gmailNotice" :tone="gmailNotice.tone">{{ gmailNotice.text }}</Alert>

      <div v-if="gmail.connected" class="flex flex-wrap items-center gap-3 rounded-lg bg-emerald-50 p-3 ring-1 ring-emerald-200">
        <span class="font-semibold text-emerald-800">✓ Gmail connecté : {{ gmail.email }}</span>
        <button class="btn-ghost ml-auto" type="button" @click="connectGmail">Reconnecter</button>
        <button class="text-red-700 underline" type="button" @click="disconnectGmail">Déconnecter</button>
      </div>

      <template v-else>
        <p v-if="gmail.configured" class="text-slate-600">Dernière étape : autorisez l'envoi d'e-mails depuis votre compte Gmail.</p>
        <button v-if="gmail.configured" class="btn-brand" type="button" @click="connectGmail">Se connecter avec Google</button>

        <details class="rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200" :open="!gmail.configured">
          <summary class="cursor-pointer font-medium">{{ gmail.configured ? 'Application Google (réglée)' : 'Une seule fois : créer l\'application Google (5 minutes)' }}</summary>
          <ol class="mt-3 list-decimal space-y-2 pl-5 text-slate-700">
            <li>Ouvrez <a href="https://console.cloud.google.com/" target="_blank" rel="noopener" class="text-emerald-700 underline">console.cloud.google.com</a> avec votre compte Gmail et créez un projet (par exemple « Jibiat Marchand »).</li>
            <li>API et services › Bibliothèque : activez « Gmail API ».</li>
            <li>API et services › Écran de consentement OAuth : type « Externe », nom « Jibiat Marchand », votre adresse ; ajoutez votre Gmail comme utilisateur test, puis touchez « Publier l'application » (sinon la connexion expire au bout de 7 jours).</li>
            <li>API et services › Identifiants › Créer des identifiants › « ID client OAuth », type « Application Web ». Dans « URI de redirection autorisés », collez :
              <span class="mt-1 flex flex-wrap items-center gap-2"><code class="rounded bg-white px-2 py-1 ring-1 ring-slate-200">{{ gmail.redirect_uri }}</code><button type="button" class="text-emerald-700 underline" @click="copyRedirect">{{ copied ? 'Copié ✓' : 'Copier' }}</button></span>
            </li>
            <li>Copiez l'« ID client » et le « Code secret du client » ci-dessous, puis « Se connecter avec Google ». Google affichera peut-être « Google n'a pas validé cette application » : c'est normal pour votre propre application, touchez « Paramètres avancés » puis « Accéder à Jibiat Marchand ».</li>
          </ol>
          <div class="mt-3 grid max-w-2xl gap-3 sm:grid-cols-2">
            <label>ID client<input v-model="google.client_id" class="input mt-1" placeholder="….apps.googleusercontent.com" autocomplete="off" /></label>
            <label>Code secret du client<input v-model="google.client_secret" type="password" class="input mt-1" autocomplete="new-password" :placeholder="gmail.configured ? 'Déjà enregistré : laisser vide pour le garder' : 'GOCSPX-…'" /></label>
          </div>
          <button class="btn-secondary mt-3" type="button" @click="saveGoogleClient">Enregistrer l'application Google</button>
        </details>
      </template>

      <details class="rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200">
        <summary class="cursor-pointer font-medium text-slate-600">Autre serveur d'e-mail (SMTP, avancé)</summary>
        <p class="mt-2 text-xs text-slate-600">Utilisé seulement si Gmail n'est pas connecté.</p>
        <div class="mt-2 grid max-w-xl gap-3 sm:grid-cols-[1fr_6rem]">
          <label>Serveur<input v-model="mail.host" class="input mt-1" placeholder="smtp.exemple.com" /></label>
          <label>Port<input v-model.number="mail.port" type="number" class="input mt-1" /></label>
        </div>
        <div class="mt-3 grid max-w-xl gap-3 sm:grid-cols-2">
          <label>Identifiant<input v-model="mail.username" class="input mt-1" autocomplete="off" /></label>
          <label>Mot de passe<input v-model="mail.password" type="password" class="input mt-1" autocomplete="new-password" :placeholder="settings.mail.password_set ? 'Déjà enregistré : laisser vide pour le garder' : ''" /></label>
          <label class="sm:col-span-2">Adresse d'expédition<input v-model="mail.from_address" type="email" class="input mt-1" /></label>
        </div>
        <div class="mt-3 flex items-center gap-2"><button class="btn-secondary" @click="saveMail">Enregistrer</button><span v-if="mailSaved" class="text-emerald-700">Enregistré ✓</span></div>
      </details>
    </section>
  </div>
</template>
