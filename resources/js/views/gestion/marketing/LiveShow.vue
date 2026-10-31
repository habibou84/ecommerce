<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { boot } from '../../../lib/boot'

/**
 * Afficher mes articles dans le live : une question (avec quoi faites-vous le live ?) qui ouvre la méthode
 * conseillée, puis une carte par méthode (télé ou tablette, ordinateur TikTok LIVE Studio / OBS, partage d'écran
 * TikTok, PRISM, cartes dans la galerie) : illustration, à quoi ça sert, pour qui, ce qu'il faut, étapes, bouton.
 */
const props = defineProps({ id: { type: [String, Number], required: true } })

const KEY = 'jibiat-live-setup'
const DEVICES = [
  ['phone', '📱', 'Mon téléphone seul'],
  ['phone_tv', '📱📺', 'Téléphone + télé'],
  ['computer', '💻', 'Un ordinateur'],
]
// Méthode conseillée selon la façon de faire le live
const BEST = { phone: 'share', phone_tv: 'tv', computer: 'studio' }
const device = ref(read())
const open = ref(BEST[device.value] ?? null)

function read() {
  try {
    return localStorage.getItem(KEY)
  } catch {
    return null
  }
}
function choose(value) {
  device.value = value
  open.value = BEST[value]
  if (['studio', 'prism'].includes(open.value)) loadLink()
  try {
    localStorage.setItem(KEY, value)
  } catch {
    // sans importance
  }
}

const screens = ref([])
const link = ref(null)
const error = ref('')
const copied = ref(false)
const code = ref('')
const name = ref('')
const pairErrors = ref({})
const paired = ref('')
const busy = ref(false)
let timer

async function load() {
  try {
    screens.value = (await http.get('/lives/screens')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
async function loadLink() {
  if (link.value) return
  try {
    link.value = (await http.post('/lives/screens/link')).data.data.url
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(() => {
  load()
  timer = setInterval(load, 10000)
  if (['studio', 'prism'].includes(open.value)) loadLink()
})
onUnmounted(() => clearInterval(timer))

function toggle(key) {
  open.value = open.value === key ? null : key
  if (['studio', 'prism'].includes(open.value)) loadLink()
}

async function copy() {
  await loadLink()
  try {
    await navigator.clipboard.writeText(link.value)
    copied.value = true
    setTimeout(() => (copied.value = false), 3000)
  } catch {
    window.prompt('Copiez ce lien :', link.value)
  }
}

async function pair() {
  busy.value = true
  pairErrors.value = {}
  paired.value = ''
  try {
    const screen = (await http.post('/lives/screens/pair', { code: code.value, name: name.value })).data.data
    paired.value = `« ${screen.name} » est relié ✓ Il affiche maintenant votre live.`
    code.value = ''
    name.value = ''
    await load()
  } catch (e) {
    pairErrors.value = fieldErrors(e)
    if (!pairErrors.value.code) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function remove(screen) {
  if (!window.confirm(`Retirer « ${screen.name} » ? Il n'affichera plus votre live.`)) return
  await http.delete(`/lives/screens/${screen.id}`)
  if (screen.is_link) link.value = null
  await load()
}

const host = String(boot.platformUrl ?? `https://${boot.domain}`).replace(/^https?:\/\//, '').replace(/\/$/, '')

const METHODS = computed(() => [
  {
    key: 'studio', icon: '💻', title: 'TikTok LIVE Studio ou OBS', ill: 'pc',
    short: 'Sur ordinateur : vos articles en haut de la vidéo, vous en bas.',
    why: 'Vos articles s\'affichent en haut de la vidéo et vous en bas : ils changent tout seuls quand vous touchez « Montrer ».',
    who: 'Live depuis un ordinateur', need: 'Une webcam et le lien ci-dessous',
    steps: ['Dans LIVE Studio (ou OBS) : « Ajouter une source » › <b>Page web</b> (ou « Navigateur »).', 'Collez le lien, taille <b>720 × 640</b>.', 'Placez la page <b>en haut</b>, la caméra <b>en bas</b>.', 'Pendant le live, touchez « Montrer » sur votre téléphone.'],
  },
  {
    key: 'tv', icon: '📺', title: 'Télé ou tablette derrière vous', ill: 'tv',
    short: 'Posée derrière vous, filmée avec vous : elle change toute seule.',
    why: 'Filmée avec vous : le code, le prix et « Plus que 2 ! » restent visibles en permanence, et l\'écran suit tout seul.',
    who: 'Live au téléphone, avec un 2ᵉ écran', need: 'Une Smart TV, une box Android ou une tablette, avec internet',
    steps: [`Sur la télé ou la tablette, ouvrez <b>${host}/ecran</b>.`, 'Un code à 4 chiffres s\'affiche : tapez-le ci-dessous.', 'Posez l\'écran derrière vous, en léger biais pour éviter les reflets.'],
  },
  {
    key: 'share', icon: '📲', title: 'Partage d\'écran TikTok', ill: 'share',
    short: 'Téléphone seul : l\'article en grand en haut, votre visage en bas.',
    why: 'Sans autre appareil : vous partagez l\'écran du téléphone, l\'article s\'affiche en grand et votre visage apparaît en petit.',
    who: 'Live au téléphone seul', need: 'L\'option « Jeux mobiles » de TikTok LIVE (selon votre compte)',
    steps: ['Activez « Ne pas déranger » sur le téléphone.', 'Ouvrez le mode présentation ci-dessous, puis dans TikTok : <b>LIVE</b> › <b>Jeux mobiles</b>.', 'Revenez sur le mode présentation : glissez pour changer d\'article, appui long pour « Comment commander ».'],
  },
  {
    key: 'prism', icon: '🟣', title: 'Application PRISM', ill: 'prism',
    short: 'Le même lien, par-dessus la vidéo du téléphone.',
    why: 'L\'application PRISM permet d\'ajouter une page web par-dessus votre vidéo : vos articles changent tout seuls pendant le live.',
    who: 'Live au téléphone avec PRISM (Facebook…)', need: 'L\'application PRISM Live Studio et le lien ci-dessous',
    steps: ['Dans PRISM, ouvrez « Mon studio » (glissez vers la droite).', 'Ajoutez un <b>Widget web</b> et collez le lien.', 'Placez-le en haut de l\'écran, puis lancez le live.'],
  },
  {
    key: 'cards', icon: '🖼️', title: 'Cartes dans la galerie', ill: 'cards',
    short: 'Une image par article, à poser dans un coin du live.',
    why: 'Sans rien installer : une image par article (code, prix, « Envoyez A1 sur WhatsApp ») à afficher dans un coin du live TikTok.',
    who: 'Tout le monde', need: 'Rien : les cartes vont dans la galerie',
    steps: ['Préparez les cartes et enregistrez-les dans la galerie.', 'Pendant le live, touchez « Effets » › « Green Screen » (ou l\'effet image).', 'Choisissez la carte, réduisez-la et posez-la dans un coin.'],
  },
])
const best = computed(() => BEST[device.value] ?? null)
const ordered = computed(() => [...METHODS.value].sort((a, b) => (b.key === best.value) - (a.key === best.value)))
const online = computed(() => screens.value.filter((s) => s.online).length)
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-4">
    <RouterLink :to="`/gestion/live/${id}`" class="inline-flex items-center gap-1 text-sm text-emerald-700 hover:underline">← Retour au live</RouterLink>

    <section class="rounded-[22px] bg-[radial-gradient(circle_at_80%_0%,#1e293b,#020617_75%)] p-5 text-white">
      <h1 class="text-2xl font-extrabold leading-tight">Montrez le code et le prix en grand pendant votre live</h1>
      <p class="mt-2 text-sm text-slate-300">Les clientes voient tout de suite quoi envoyer sur WhatsApp : moins de questions dans les commentaires, plus de commandes.</p>
      <span class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-3 py-1 text-xs font-bold text-emerald-300">🔒 Aucun nom, numéro ni montant à l'écran</span>
    </section>
    <Alert v-if="error">{{ error }}</Alert>

    <div>
      <p class="mb-2 px-0.5 font-extrabold">Avec quoi faites-vous votre live ?</p>
      <div class="grid grid-cols-3 gap-2">
        <button v-for="[value, icon, label] in DEVICES" :key="value" type="button" class="rounded-2xl border-2 bg-white px-1.5 py-3 text-center text-sm font-extrabold leading-tight" :class="device === value ? 'border-emerald-700 bg-emerald-50 text-emerald-900 ring-4 ring-emerald-100' : 'border-slate-200 text-slate-700'" @click="choose(value)">
          <span class="mb-1 block text-2xl">{{ icon }}</span>{{ label }}
        </button>
      </div>
    </div>

    <p v-if="best" class="px-0.5 text-xs font-extrabold uppercase tracking-wide text-slate-500">Conseillé pour vous, puis les autres façons</p>

    <article v-for="m in ordered" :key="m.key" class="overflow-hidden rounded-[20px] bg-white" :class="m.key === best ? 'border-2 border-emerald-700 shadow-lg shadow-emerald-900/10' : 'border border-slate-200'">
      <!-- Résumé : touche pour ouvrir -->
      <button v-if="open !== m.key" type="button" class="flex w-full items-center gap-3 p-3 text-left" @click="toggle(m.key)">
        <span class="live-ill live-ill-small" :class="`ill-${m.ill}`" />
        <span class="min-w-0 flex-1">
          <span v-if="m.key === best" class="mb-0.5 inline-block rounded-full bg-emerald-700 px-2 py-0.5 text-[11px] font-bold text-white">✓ Conseillé pour vous</span>
          <b class="block text-[15px]">{{ m.icon }} {{ m.title }}</b>
          <span class="block text-xs text-slate-500">{{ m.short }}</span>
        </span>
        <span class="text-xl text-slate-400">›</span>
      </button>

      <!-- Méthode ouverte -->
      <template v-else>
        <div class="live-ill live-ill-big relative" :class="`ill-${m.ill}`">
          <span v-if="m.key === best" class="absolute left-2.5 top-2.5 z-10 rounded-full bg-emerald-700 px-2.5 py-1 text-[11px] font-bold text-white">✓ Conseillé pour vous</span>
        </div>
        <div class="space-y-3 p-4">
          <button type="button" class="flex w-full items-center gap-2 text-left" @click="toggle(m.key)">
            <h2 class="flex-1 text-lg font-extrabold">{{ m.icon }} {{ m.title }}</h2>
            <span class="text-sm text-slate-400">Fermer</span>
          </button>
          <p class="text-sm text-slate-600">{{ m.why }}</p>
          <div class="grid grid-cols-2 gap-2 text-xs">
            <div class="rounded-xl bg-slate-50 p-2.5"><b class="mb-0.5 block text-[10.5px] uppercase tracking-wide text-slate-500">Pour qui</b>{{ m.who }}</div>
            <div class="rounded-xl bg-slate-50 p-2.5"><b class="mb-0.5 block text-[10.5px] uppercase tracking-wide text-slate-500">Ce qu'il faut</b>{{ m.need }}</div>
          </div>

          <!-- Lien de l'écran (ordinateur, PRISM) -->
          <template v-if="['studio', 'prism'].includes(m.key)">
            <p class="break-all rounded-xl border-2 border-dashed border-slate-400 bg-slate-50 p-3 font-mono text-xs">{{ link ?? 'Préparation du lien…' }}</p>
            <button type="button" class="btn-brand w-full py-3 text-base" @click="copy">{{ copied ? '✓ Lien copié' : '📋 Copier le lien de l\'écran' }}</button>
          </template>

          <ol class="space-y-2">
            <li v-for="(s, i) in m.steps" :key="i" class="flex gap-2.5 text-sm">
              <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-emerald-50 text-xs font-black text-emerald-700">{{ i + 1 }}</span>
              <span v-html="s" />
            </li>
          </ol>

          <!-- Télé : relier par le code -->
          <form v-if="m.key === 'tv'" class="space-y-2" @submit.prevent="pair">
            <input v-model="code" class="input text-center text-3xl font-black tracking-[.5em]" inputmode="numeric" maxlength="4" placeholder="····" autocomplete="off" aria-label="Code de l'écran" />
            <p v-if="pairErrors.code" class="text-sm text-red-600">{{ pairErrors.code }}</p>
            <input v-model="name" class="input" maxlength="60" placeholder="Nom de l'écran (ex. Télé salon)" />
            <button class="btn-brand w-full py-3 text-base" :disabled="busy || code.length !== 4">{{ busy ? 'Liaison…' : 'Relier cet écran' }}</button>
            <p v-if="paired" class="rounded-xl bg-emerald-50 p-2.5 text-sm text-emerald-800">{{ paired }}</p>
            <p class="rounded-xl border border-slate-200 p-2.5 text-center text-xs text-slate-600">💡 Astuce : fond sombre et luminosité un peu baissée, la caméra filme mieux.</p>
          </form>
          <RouterLink v-if="m.key === 'share'" :to="`/gestion/live/${id}/presentation`" class="btn-brand block w-full py-3 text-center text-base">Ouvrir le mode présentation</RouterLink>
          <RouterLink v-if="m.key === 'cards'" :to="`/gestion/live/${id}/cartes`" class="btn-brand block w-full py-3 text-center text-base">Préparer les cartes</RouterLink>
        </div>
      </template>
    </article>

    <!-- Écrans reliés -->
    <section class="space-y-2">
      <p class="px-0.5 text-xs font-extrabold uppercase tracking-wide text-slate-500">Mes écrans<template v-if="screens.length"> · {{ online }} allumé{{ online > 1 ? 's' : '' }}</template></p>
      <div class="card divide-y divide-slate-100 px-4 py-1 text-sm">
        <p v-if="!screens.length" class="py-3 text-slate-500">Aucun écran pour l'instant : reliez une télé, une tablette ou copiez le lien pour l'ordinateur.</p>
        <div v-for="s in screens" :key="s.id" class="flex items-center gap-2 py-2.5">
          <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="s.online ? 'bg-emerald-500' : 'bg-slate-300'" />
          <span class="min-w-0 flex-1 truncate" :class="s.online ? 'font-semibold' : ''">{{ s.name }}</span>
          <span class="text-xs" :class="s.online ? 'font-bold text-emerald-700' : 'text-slate-500'">{{ s.online ? 'allumé' : 'éteint' }}</span>
          <button type="button" class="text-xs text-red-600" @click="remove(s)">Retirer</button>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.live-ill { position: relative; overflow: hidden; flex-shrink: 0; }
.live-ill-small { width: 64px; height: 64px; border-radius: 14px; }
.live-ill-big { height: 130px; }
/* Ordinateur : vidéo verticale, articles en haut, vendeuse en bas */
.ill-pc { background: linear-gradient(180deg, #e2e8f0, #cbd5e1); }
.ill-pc::before { content: ''; position: absolute; left: 50%; top: 18%; width: 62%; height: 64%; transform: translateX(-50%); background: #111; border-radius: 6px; }
.ill-pc::after { content: ''; position: absolute; left: 50%; top: 24%; width: 22%; height: 52%; transform: translateX(-50%); background: linear-gradient(180deg, #ea580c 0 50%, #c4ad95 50% 100%); border-radius: 2px; }
/* Télé derrière la vendeuse */
.ill-tv { background: linear-gradient(180deg, #d6c4b0, #c4ad95); }
.ill-tv::before { content: ''; position: absolute; left: 10%; top: 14%; width: 50%; height: 56%; background: radial-gradient(circle at 20% 0, #334155, #020617); border: 4px solid #111; border-radius: 6px; box-shadow: inset 12px 0 0 0 #ea580c; }
.ill-tv::after { content: ''; position: absolute; right: 14%; bottom: 0; width: 34%; height: 70%; background: repeating-linear-gradient(60deg, #1d4ed8 0 7px, #facc15 7px 14px, #dc2626 14px 21px); border-radius: 50% 50% 0 0; }
/* Partage d'écran : article en haut, bulle du visage */
.ill-share { background: radial-gradient(circle at 30% 0, #1e293b, #020617); }
.ill-share::before { content: ''; position: absolute; left: 14%; right: 14%; top: 12%; height: 50%; background: linear-gradient(135deg, #f97316, #be123c); border-radius: 8px; }
.ill-share::after { content: ''; position: absolute; left: 12%; bottom: 10%; width: 22%; aspect-ratio: 1; border-radius: 50%; background: #d6c4b0; border: 3px solid #fff; }
/* PRISM */
.ill-prism { background: linear-gradient(135deg, #a855f7, #6366f1); }
.ill-prism::before { content: 'PRISM'; position: absolute; inset: 0; display: grid; place-items: center; color: #fff; font-weight: 900; font-size: 13px; letter-spacing: .05em; }
.live-ill-big.ill-prism::before { font-size: 28px; }
/* Cartes */
.ill-cards { background: #facc15; }
.ill-cards::before, .ill-cards::after { content: ''; position: absolute; top: 18%; width: 30%; height: 64%; border-radius: 6px; border: 2px solid #fff; }
.ill-cards::before { left: 20%; background: linear-gradient(135deg, #f97316, #be123c); transform: rotate(-8deg); }
.ill-cards::after { right: 20%; background: linear-gradient(135deg, #34d399, #0f766e); transform: rotate(8deg); }
</style>
