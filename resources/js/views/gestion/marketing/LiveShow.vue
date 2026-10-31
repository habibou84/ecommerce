<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import LiveDisplay from '../../../components/LiveDisplay.vue'
import Modal from '../../../components/Modal.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { boot } from '../../../lib/boot'

/**
 * Afficher mes articles dans le live : d'abord le principe (un seul affichage qui suit le live, on choisit où il
 * apparaît), puis deux questions (avec quoi vous filmez ? une télé ou une tablette derrière vous ?) qui donnent une
 * seule façon de faire : ce que voient les clientes, facilité, ce qu'il faut, étapes, « Tester maintenant » et
 * « Ça ne marche pas ? ». Les autres façons restent repliées en bas.
 */
const props = defineProps({ id: { type: [String, Number], required: true } })

const KEY = 'jibiat-live-setup-v2'
const OLD_KEY = 'jibiat-live-setup'
const answers = ref(read())
const filming = computed(() => answers.value.filming ?? null)
const second = computed(() => answers.value.second ?? null)

function read() {
  try {
    const saved = JSON.parse(localStorage.getItem(KEY) || 'null')
    if (saved) return saved
    // Réponse de l'ancienne page (une seule question)
    return { phone: { filming: 'phone', second: 'no' }, phone_tv: { filming: 'phone', second: 'yes' }, computer: { filming: 'computer' } }[localStorage.getItem(OLD_KEY)] ?? {}
  } catch {
    return {}
  }
}
function answer(field, value) {
  answers.value = { ...answers.value, [field]: value }
  if (field === 'filming' && value === 'computer') answers.value.second = null
  open.value = best.value
  if (needsLink(open.value)) loadLink()
  try {
    localStorage.setItem(KEY, JSON.stringify(answers.value))
  } catch {
    // sans importance
  }
}

// Façon conseillée selon les réponses
const best = computed(() => {
  if (filming.value === 'computer') return 'studio'
  if (filming.value === 'phone' && second.value === 'yes') return 'tv'
  if (filming.value === 'phone' && second.value === 'no') return 'share'
  return null
})
const open = ref(null)
open.value = best.value
const needsLink = (key) => ['studio', 'prism'].includes(key)

const screens = ref([])
const link = ref(null)
const error = ref('')
const copied = ref(false)
const code = ref('')
const name = ref('')
const pairErrors = ref({})
const paired = ref('')
const busy = ref(false)
const pairing = ref(false)
const term = ref(null)
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
  if (needsLink(open.value)) loadLink()
})
onUnmounted(() => {
  clearInterval(timer)
  clearInterval(previewTimer)
})

function show(key) {
  open.value = key
  term.value = null
  if (needsLink(key)) loadLink()
  window.scrollTo({ top: document.getElementById('facon')?.offsetTop - 70 || 0, behavior: 'smooth' })
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

// « Relier un écran » ouvre la saisie du code affiché sur la télé
async function startPair() {
  if (open.value !== 'tv') show('tv')
  paired.value = ''
  pairErrors.value = {}
  pairing.value = true
  await nextTick()
  document.getElementById('screen-code')?.focus()
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
    pairing.value = false
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

// « Tester maintenant » : l'affichage tel que le verront les clientes, dans le format de la façon choisie
const preview = ref(null)
let previewTimer
async function loadPreview() {
  try {
    preview.value = { ...preview.value, data: (await http.get(`/lives/${props.id}/display`)).data.data }
  } catch (e) {
    error.value = errorMessage(e)
    closePreview()
  }
}
function openPreview(method) {
  preview.value = { method, data: null }
  loadPreview()
  previewTimer = setInterval(loadPreview, 3000)
}
function closePreview() {
  clearInterval(previewTimer)
  preview.value = null
}

const host = String(boot.platformUrl ?? `https://${boot.domain}`).replace(/^https?:\/\//, '').replace(/\/$/, '')

// Mots techniques : un « ? » les explique en une phrase
const TERMS = {
  'Jeux mobiles': '« Jeux mobiles » : l\'option de TikTok LIVE qui montre l\'écran de votre téléphone pendant le live. Malgré son nom, elle marche avec n\'importe quelle application.',
  'Page web': '« Page web » (ou « Navigateur ») : une source qui affiche un site dans la vidéo du live. Vous y collez le lien de votre écran.',
  OBS: 'OBS : un logiciel gratuit pour faire des lives depuis un ordinateur, comme TikTok LIVE Studio.',
  PRISM: 'PRISM Live Studio : une application gratuite (Android, iPhone) pour faire des lives TikTok, Facebook ou YouTube avec des éléments ajoutés par-dessus la vidéo.',
  'Widget web': '« Widget web » : dans PRISM, un cadre qui affiche un site par-dessus votre vidéo.',
  'Green Screen': '« Green Screen » : l\'effet de TikTok qui met une image de votre galerie dans le live.',
}
// Une étape : texte, avec les mots techniques entre [[ ]]
const parts = (step) => step.split(/\[\[(.+?)\]\]/).map((text, i) => ({ text, term: i % 2 === 1 }))

const METHODS = computed(() => [
  {
    key: 'tv', icon: '📺', title: 'La télé derrière vous', short: 'Avec une télé ou une tablette', scene: 'tv', ease: 3, auto: true,
    explain: 'Vous filmez avec votre téléphone, comme d\'habitude. La télé est derrière vous et montre l\'article, le code et le prix : elle change toute seule quand vous touchez « Montrer ».',
    need: 'Télé connectée ou tablette', format: 'landscape',
    steps: [`Sur la télé, ouvrez le navigateur et tapez <b>${host}/ecran</b>.`, 'Touchez « Relier un écran » ci-dessous, puis tapez les 4 chiffres affichés sur la télé.', 'Posez l\'écran derrière vous, en léger biais pour éviter les reflets.'],
    help: { title: 'Ça ne marche pas ?', text: 'Pas de navigateur sur la télé ? Une tablette ou un vieux téléphone posé sur un support fait pareil. Rien de tout ça ?', go: [['share', 'L\'article en grand sur votre téléphone']] },
  },
  {
    key: 'share', icon: '📲', title: 'L\'article en grand sur votre téléphone', short: 'Téléphone seul, partage d\'écran', scene: 'share', ease: 2, auto: true,
    explain: 'Votre téléphone montre l\'article en grand, et votre visage apparaît en petit dans un coin. Vous glissez pour passer à l\'article suivant.',
    need: 'Seulement le téléphone', format: 'portrait',
    steps: ['Ouvrez le mode présentation (bouton ci-dessous).', 'Dans TikTok : <b>LIVE</b> › [[Jeux mobiles]], puis lancez le live.', 'Revenez sur le mode présentation : glissez pour changer d\'article, appui long pour « Comment commander ».'],
    help: { title: 'Vous ne voyez pas « Jeux mobiles » ?', text: 'TikTok ne l\'ouvre pas encore à tous les comptes. Utilisez plutôt :', go: [['prism', 'l\'appli PRISM'], ['cards', 'les images à poser vous-même']] },
  },
  {
    key: 'studio', icon: '💻', title: 'Articles incrustés dans la vidéo', short: 'Live depuis un ordinateur', scene: 'studio', ease: 1, auto: true,
    explain: 'Vos articles s\'affichent en haut de la vidéo et vous en bas. Ils changent tout seuls quand vous touchez « Montrer » sur votre téléphone.',
    need: 'TikTok LIVE Studio ou [[OBS]], une webcam', format: 'studio',
    steps: ['Dans LIVE Studio (ou [[OBS]]) : « Ajouter une source » › [[Page web]].', 'Collez le lien ci-dessus, taille <b>720 × 640</b>.', 'Placez la page <b>en haut</b>, la caméra <b>en bas</b>.', 'Pendant le live, touchez « Montrer » sur votre téléphone.'],
    help: { title: 'Ça ne marche pas ?', text: 'La page reste blanche ? Vérifiez que le lien est collé en entier, puis rechargez la source. Pas de webcam ?', go: [['tv', 'La télé derrière vous']] },
  },
  {
    key: 'prism', icon: '🟣', title: 'Incrustés depuis le téléphone', short: 'Avec l\'appli PRISM', scene: 'studio', ease: 1, auto: true,
    explain: 'Comme sur ordinateur, mais depuis le téléphone : l\'appli [[PRISM]] met vos articles par-dessus votre vidéo, et ils changent tout seuls.',
    need: 'L\'appli [[PRISM]] (gratuite)', format: 'studio',
    steps: ['Installez [[PRISM]] et connectez votre compte TikTok ou Facebook.', 'Dans PRISM, ouvrez « Mon studio » (glissez vers la droite).', 'Ajoutez un [[Widget web]] et collez le lien ci-dessus.', 'Placez-le en haut de l\'écran, puis lancez le live.'],
    help: { title: 'Ça ne marche pas ?', text: 'Votre compte n\'apparaît pas dans PRISM ? Restez simple :', go: [['cards', 'les images à poser vous-même']] },
  },
  {
    key: 'cards', icon: '🖼️', title: 'Images à poser vous-même', short: 'Ne changent pas toutes seules', scene: 'cards', ease: 3, auto: false,
    explain: 'Une image par article (code, prix, « Envoyez A1 sur WhatsApp ») enregistrée dans votre galerie. Vous la posez dans un coin du live et la changez à la main.',
    need: 'Rien à installer', format: null,
    steps: ['Préparez les cartes et enregistrez-les dans la galerie.', 'Pendant le live, touchez « Effets » › [[Green Screen]].', 'Choisissez la carte, réduisez-la et posez-la dans un coin.'],
    help: null,
  },
])
const current = computed(() => METHODS.value.find((m) => m.key === open.value) ?? null)
const others = computed(() => METHODS.value.filter((m) => m.key !== open.value))
const online = computed(() => screens.value.filter((s) => s.online).length)
const stars = (n) => ['★'.repeat(n), '★'.repeat(3 - n)]
const EASE = { 1: 'plus technique', 2: 'moyen', 3: 'facile' }
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-4">
    <RouterLink :to="`/gestion/live/${id}`" class="inline-flex items-center gap-1 text-sm text-emerald-700 hover:underline">← Retour au live</RouterLink>

    <!-- Le principe : un seul affichage, vous choisissez où il apparaît -->
    <section class="rounded-[22px] bg-[radial-gradient(circle_at_80%_0%,#1e293b,#020617_75%)] p-5 text-white">
      <h1 class="text-2xl font-extrabold leading-tight">Le code et le prix en grand pendant votre live</h1>
      <div class="mt-4 grid grid-cols-[1fr_auto_1fr_auto_1fr] items-start gap-1.5 text-center text-[11.5px] leading-tight text-slate-300">
        <div><div class="mb-1.5 grid h-16 place-items-center rounded-xl bg-white"><span class="rounded-lg bg-emerald-700 px-2 py-1.5 text-xs font-extrabold text-white">Montrer A3</span></div>Vous touchez « Montrer »</div>
        <span class="pt-6 font-black text-slate-500">›</span>
        <div><div class="relative mb-1.5 h-16 rounded-xl bg-gradient-to-br from-orange-500 to-rose-700"><span class="absolute left-1.5 top-1.5 rounded border-2 border-white bg-red-600 px-1.5 text-xs font-black">A3</span><span class="absolute bottom-1.5 right-1.5 rounded bg-yellow-400 px-1.5 py-0.5 text-[10px] font-black text-slate-900">12 000 F</span></div>L'affichage change tout seul</div>
        <span class="pt-6 font-black text-slate-500">›</span>
        <div><div class="mb-1.5 flex h-16 flex-col justify-center gap-1 rounded-xl bg-emerald-100 p-1.5"><span class="self-end rounded-lg rounded-br-sm bg-emerald-300 px-2 text-xs font-extrabold text-emerald-900">A3</span><span class="self-start rounded-lg rounded-bl-sm bg-white px-1.5 text-[10px] text-slate-700">Réservé ✓</span></div>La cliente envoie « A3 »</div>
      </div>
      <p class="mt-4 rounded-xl border border-yellow-400/45 bg-yellow-400/15 px-3 py-2 text-sm font-bold text-yellow-200">👉 Vous choisissez seulement <u>où</u> cet affichage apparaît.</p>
      <p class="mt-2 text-xs text-slate-400">🔒 Aucun nom, numéro ni montant de commande à l'écran.</p>
    </section>
    <Alert v-if="error">{{ error }}</Alert>

    <!-- Deux questions -->
    <section class="card p-4">
      <p class="mb-2 font-extrabold">1. Avec quoi filmez-vous votre live ?</p>
      <div class="grid grid-cols-2 gap-2">
        <button v-for="[value, icon, label, hint] in [['phone', '📱', 'Mon téléphone', 'TikTok, Facebook…'], ['computer', '💻', 'Un ordinateur', 'avec une webcam']]" :key="value" type="button" class="rounded-2xl border-2 bg-white px-2 py-3 text-center font-extrabold" :class="filming === value ? 'border-emerald-700 bg-emerald-50 text-emerald-900 ring-4 ring-emerald-100' : 'border-slate-200 text-slate-700'" :aria-pressed="filming === value" @click="answer('filming', value)">
          <span class="mb-1 block text-2xl">{{ icon }}</span>{{ label }}<span class="block text-xs font-medium text-slate-500">{{ hint }}</span>
        </button>
      </div>
    </section>
    <section v-if="filming === 'phone'" class="card p-4">
      <p class="font-extrabold">2. Avez-vous une télé ou une tablette à poser derrière vous ?</p>
      <p class="mb-2 text-sm text-slate-500">Elle sera filmée avec vous, comme une vitrine.</p>
      <div class="grid grid-cols-2 gap-2">
        <button v-for="[value, icon, label] in [['yes', '📺', 'Oui'], ['no', '🚫', 'Non']]" :key="value" type="button" class="rounded-2xl border-2 bg-white px-2 py-3 text-center font-extrabold" :class="second === value ? 'border-emerald-700 bg-emerald-50 text-emerald-900 ring-4 ring-emerald-100' : 'border-slate-200 text-slate-700'" :aria-pressed="second === value" @click="answer('second', value)">
          <span class="mb-1 block text-2xl">{{ icon }}</span>{{ label }}
        </button>
      </div>
    </section>

    <!-- La façon de faire -->
    <article v-if="current" id="facon" class="overflow-hidden rounded-[20px] border-2 bg-white" :class="current.key === best ? 'border-emerald-700 shadow-lg shadow-emerald-900/10' : 'border-slate-300'">
      <div class="bg-slate-900 pt-3 text-center text-[11px] font-extrabold tracking-wider text-slate-400">
        CE QUE VOIENT VOS CLIENTES
        <div class="scene" :class="`scene-${current.scene}`" aria-hidden="true">
          <template v-if="current.scene === 'tv'">
            <div class="tv"><div class="img" /><i>A3</i><b>12 000 F</b><em>Plus que 2</em></div>
            <div class="person"><div class="h" /><div class="bd" /></div>
            <div class="comments"><span>A3 svp 🙏</span><span>Fatou : A3</span></div>
          </template>
          <template v-else-if="current.scene === 'share'">
            <div class="big"><i>A3</i></div>
            <div class="price">12 000 F<s>15 000 F</s></div>
            <div class="send">Envoyez « A3 » sur WhatsApp</div>
            <div class="face" />
          </template>
          <template v-else-if="current.scene === 'studio'">
            <div class="band"><div class="img" /><i>A3</i><b>12 000 F</b></div>
            <div class="person"><div class="h" /><div class="bd" /></div>
          </template>
          <template v-else>
            <div class="person"><div class="h" /><div class="bd" /></div>
            <div class="card-img"><i>A3</i><b>12 000 F</b></div>
          </template>
        </div>
      </div>

      <div class="space-y-3 p-4">
        <span v-if="current.key === best" class="inline-block rounded-full bg-emerald-700 px-2.5 py-1 text-xs font-bold text-white">✓ Votre façon de faire</span>
        <button v-else-if="best" type="button" class="text-sm font-semibold text-emerald-700 underline" @click="show(best)">← Revenir à la façon conseillée pour vous</button>
        <h2 class="text-xl font-extrabold leading-tight">{{ current.icon }} {{ current.title }}</h2>
        <p class="text-sm text-slate-600">
          <template v-for="(p, i) in parts(current.explain)" :key="i">
            <button v-if="p.term" type="button" class="term" @click="term = term === p.text ? null : p.text">{{ p.text }}<span class="q">?</span></button>
            <template v-else>{{ p.text }}</template>
          </template>
        </p>
        <div class="grid grid-cols-3 gap-2 text-xs leading-tight">
          <div class="rounded-xl bg-slate-100 p-2"><b class="fact">Facilité</b><span class="text-amber-500">{{ stars(current.ease)[0] }}</span><span class="text-slate-300">{{ stars(current.ease)[1] }}</span> {{ EASE[current.ease] }}</div>
          <div class="rounded-xl bg-slate-100 p-2"><b class="fact">Change tout seul</b><span v-if="current.auto" class="font-extrabold text-emerald-700">✓ Oui</span><span v-else class="font-extrabold text-slate-600">Non, à la main</span></div>
          <div class="rounded-xl bg-slate-100 p-2"><b class="fact">Il vous faut</b>{{ current.need.replace(/\[\[|\]\]/g, '') }}</div>
        </div>

        <!-- Lien de l'écran (ordinateur, PRISM) -->
        <template v-if="needsLink(current.key)">
          <p class="break-all rounded-xl border-2 border-dashed border-slate-400 bg-slate-50 p-3 font-mono text-xs">{{ link ?? 'Préparation du lien…' }}</p>
          <button type="button" class="btn-brand w-full py-3 text-base" @click="copy">{{ copied ? '✓ Lien copié' : '📋 Copier le lien de l\'écran' }}</button>
        </template>

        <ol class="space-y-2">
          <li v-for="(s, i) in current.steps" :key="i" class="flex gap-2.5 text-sm">
            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-emerald-50 text-xs font-black text-emerald-700">{{ i + 1 }}</span>
            <span>
              <template v-for="(p, j) in parts(s)" :key="j">
                <button v-if="p.term" type="button" class="term" @click="term = term === p.text ? null : p.text">{{ p.text }}<span class="q">?</span></button>
                <span v-else v-html="p.text" />
              </template>
            </span>
          </li>
        </ol>
        <p v-if="term && TERMS[term]" class="rounded-xl bg-slate-900 p-3 text-sm text-slate-100">{{ TERMS[term] }}</p>

        <!-- Télé : « Relier un écran », puis le code affiché sur la télé -->
        <template v-if="current.key === 'tv'">
          <button v-if="!pairing" type="button" class="btn-brand w-full py-3 text-base" @click="startPair">📺 Relier un écran</button>
          <form v-else class="space-y-2 rounded-xl border-2 border-emerald-700 p-3" @submit.prevent="pair">
            <label for="screen-code" class="block text-sm font-semibold">Tapez les 4 chiffres affichés sur la télé</label>
            <input id="screen-code" v-model="code" class="input text-center text-3xl font-black tracking-[.5em]" inputmode="numeric" maxlength="4" placeholder="····" autocomplete="off" />
            <p v-if="pairErrors.code" class="text-sm text-red-600">{{ pairErrors.code }}</p>
            <input v-model="name" class="input" maxlength="60" placeholder="Nom de l'écran (ex. Télé salon)" aria-label="Nom de l'écran" />
            <div class="flex gap-2">
              <button type="button" class="btn-ghost" @click="pairing = false">Annuler</button>
              <button class="btn-brand flex-1 py-3 text-base" :disabled="busy || code.length !== 4">{{ busy ? 'Liaison…' : 'Valider' }}</button>
            </div>
          </form>
          <p v-if="paired" class="rounded-xl bg-emerald-50 p-2.5 text-sm text-emerald-800">{{ paired }}</p>
          <p class="text-center text-xs text-slate-500">💡 Fond sombre et luminosité un peu baissée : la caméra filme mieux.</p>
        </template>
        <RouterLink v-if="current.key === 'share'" :to="`/gestion/live/${id}/presentation`" class="btn-brand block w-full py-3 text-center text-base">Ouvrir le mode présentation</RouterLink>
        <RouterLink v-if="current.key === 'cards'" :to="`/gestion/live/${id}/cartes`" class="btn-brand block w-full py-3 text-center text-base">Préparer les cartes</RouterLink>
        <button v-if="current.format" type="button" class="block w-full rounded-xl border-2 border-emerald-200 bg-white py-3 text-center text-base font-extrabold text-emerald-700" @click="openPreview(current)">👀 Tester maintenant (avant le live)</button>

        <div v-if="current.help" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
          <b class="block text-amber-800">{{ current.help.title }}</b>
          {{ current.help.text }}
          <template v-for="([key, label], i) in current.help.go" :key="key">
            <template v-if="i"> ou </template>
            <button type="button" class="font-extrabold text-emerald-700 underline" @click="show(key)">{{ label }}</button>
          </template>.
        </div>
      </div>
    </article>

    <!-- Les autres façons -->
    <details class="overflow-hidden rounded-[18px] border border-slate-200 bg-white" :open="!current">
      <summary class="flex cursor-pointer items-center justify-between px-4 py-3 font-extrabold">
        {{ current ? 'Les autres façons de faire' : 'Ou choisissez directement' }}<span class="text-slate-400">⌄</span>
      </summary>
      <button v-for="m in others" :key="m.key" type="button" class="flex w-full items-center gap-3 border-t border-slate-100 px-4 py-2.5 text-left text-sm hover:bg-slate-50" @click="show(m.key)">
        <span class="text-lg" aria-hidden="true">{{ m.icon }}</span>
        <span class="min-w-0 flex-1"><b class="block font-semibold">{{ m.title }}</b><span class="text-xs text-slate-500">{{ m.short }}</span></span>
        <span class="whitespace-nowrap text-xs" :aria-label="`Facilité : ${EASE[m.ease]}`"><span class="text-amber-500">{{ stars(m.ease)[0] }}</span><span class="text-slate-300">{{ stars(m.ease)[1] }}</span></span>
      </button>
    </details>

    <!-- Écrans reliés -->
    <section class="space-y-2">
      <div class="flex items-center justify-between gap-2 px-0.5">
        <p class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Mes écrans<template v-if="screens.length"> · {{ online }} allumé{{ online > 1 ? 's' : '' }}</template></p>
        <button type="button" class="text-sm font-semibold text-emerald-700" @click="startPair">+ Relier un écran</button>
      </div>
      <div class="card divide-y divide-slate-100 px-4 py-1 text-sm">
        <p v-if="!screens.length" class="py-3 text-slate-500">Aucun écran pour l'instant : reliez une télé ou une tablette, ou copiez le lien pour l'ordinateur ou PRISM.</p>
        <div v-for="s in screens" :key="s.id" class="flex items-center gap-2 py-2.5">
          <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="s.online ? 'bg-emerald-500' : 'bg-slate-300'" />
          <span class="min-w-0 flex-1 truncate" :class="s.online ? 'font-semibold' : ''">{{ s.name }}</span>
          <span class="text-xs" :class="s.online ? 'font-bold text-emerald-700' : 'text-slate-500'">{{ s.online ? 'allumé' : 'éteint' }}</span>
          <button type="button" class="text-xs text-red-600" @click="remove(s)">Retirer</button>
        </div>
      </div>
    </section>

    <!-- Tester maintenant -->
    <Modal v-if="preview" :title="`Aperçu : ${preview.method.title}`" wide @close="closePreview">
      <p class="mb-3 text-sm text-slate-600">Voici ce que verront vos clientes. Touchez « Montrer » sur un article dans l'écran du live : l'aperçu change tout seul.</p>
      <div class="mx-auto overflow-hidden rounded-xl bg-black" :class="{ 'aspect-video w-full': preview.method.format === 'landscape', 'aspect-[9/16] w-[min(100%,300px)]': preview.method.format === 'portrait', 'aspect-[9/8] w-[min(100%,420px)]': preview.method.format === 'studio' }">
        <LiveDisplay v-if="preview.data" :data="preview.data" :free-bottom="preview.method.format === 'portrait' ? 0.2 : 0" />
        <p v-else class="grid h-full place-items-center text-sm text-slate-400">Chargement…</p>
      </div>
    </Modal>
  </div>
</template>

<style scoped>
.term { font-weight: 700; border-bottom: 1.5px dotted #047857; color: inherit; }
.q { display: inline-grid; place-items: center; width: 16px; height: 16px; margin-left: 3px; border-radius: 50%; background: #e2e8f0; color: #334155; font-size: 10px; font-weight: 900; vertical-align: 1px; }
.fact { display: block; margin-bottom: 2px; font-size: 9.5px; letter-spacing: .04em; text-transform: uppercase; color: #64748b; }

/* « Ce que voient vos clientes » : un téléphone qui filme le live */
.scene { position: relative; width: 160px; height: 220px; margin: 8px auto 0; overflow: hidden; border: 5px solid #334155; border-bottom: 0; border-radius: 18px 18px 0 0; text-align: left; letter-spacing: 0; }
.person { position: absolute; right: 14px; bottom: 0; width: 78px; height: 120px; }
.person .h { position: absolute; left: 24px; top: 0; width: 32px; height: 38px; border-radius: 50%; background: #7c4a2d; }
.person .bd { position: absolute; inset: auto 0 0; height: 84px; border-radius: 40px 40px 0 0; background: repeating-linear-gradient(60deg, #1d4ed8 0 7px, #facc15 7px 14px, #dc2626 14px 21px); }
.scene i { font-style: normal; font-weight: 900; color: #fff; background: #dc2626; }
.scene-tv { background: linear-gradient(180deg, #d6c4b0, #b89f86); }
.scene-tv .tv { position: absolute; left: 10px; top: 22px; width: 92px; height: 64px; overflow: hidden; background: #020617; border: 3px solid #111; border-radius: 4px; }
.scene-tv .tv .img { position: absolute; inset: 0 38% 0 0; background: linear-gradient(135deg, #f97316, #be123c); }
.scene-tv .tv i { position: absolute; left: 3px; top: 3px; padding: 0 3px; border-radius: 2px; font-size: 8px; }
.scene-tv .tv b { position: absolute; right: 3px; top: 5px; color: #fff; font-size: 9px; }
.scene-tv .tv em { position: absolute; right: 3px; bottom: 4px; padding: 1px 2px; border-radius: 2px; background: #facc15; color: #111; font-size: 7px; font-style: normal; font-weight: 900; }
.comments { position: absolute; left: 6px; bottom: 8px; display: flex; flex-direction: column; gap: 3px; }
.comments span { padding: 2px 5px; border-radius: 6px; background: rgb(0 0 0 / .45); color: #fff; font-size: 8px; font-weight: 600; }
.scene-share { background: #020617; }
.scene-share .big { position: absolute; inset: 8px 8px auto; height: 120px; border-radius: 8px; background: linear-gradient(135deg, #f97316, #be123c); }
.scene-share .big i { position: absolute; left: 5px; top: 5px; padding: 0 5px; border: 1.5px solid #fff; border-radius: 4px; font-size: 13px; }
.scene-share .price { position: absolute; left: 8px; top: 134px; color: #fff; font-size: 13px; font-weight: 900; }
.scene-share .price s { margin-left: 4px; color: #94a3b8; font-size: 10px; font-weight: 500; }
.scene-share .send { position: absolute; left: 8px; right: 54px; top: 154px; padding: 4px; border-radius: 6px; background: #16a34a; color: #fff; font-size: 8.5px; font-weight: 800; text-align: center; }
.scene-share .face { position: absolute; right: 6px; bottom: 10px; width: 40px; height: 40px; border: 2px solid #fff; border-radius: 50%; background: #7c4a2d; }
.scene-studio { background: linear-gradient(180deg, #d6c4b0, #b89f86); }
.scene-studio .band { position: absolute; inset: 0 0 auto; height: 92px; background: #020617; }
.scene-studio .band .img { position: absolute; left: 8px; top: 8px; bottom: 8px; width: 70px; border-radius: 4px; background: linear-gradient(135deg, #f97316, #be123c); }
.scene-studio .band i { position: absolute; left: 12px; top: 12px; padding: 0 4px; border: 1.5px solid #fff; border-radius: 3px; font-size: 10px; }
.scene-studio .band b { position: absolute; left: 86px; top: 30px; color: #fff; font-size: 12px; }
.scene-studio .person { right: 40px; height: 116px; }
.scene-cards { background: linear-gradient(180deg, #d6c4b0, #b89f86); }
.scene-cards .person { right: 40px; }
.scene-cards .card-img { position: absolute; right: 6px; top: 14px; width: 48px; height: 64px; border: 2px solid #fff; border-radius: 5px; background: linear-gradient(135deg, #f97316, #be123c); }
.scene-cards .card-img i { position: absolute; left: 3px; top: 3px; padding: 0 3px; border-radius: 2px; font-size: 8px; }
.scene-cards .card-img b { position: absolute; left: 3px; bottom: 3px; padding: 0 2px; border-radius: 2px; background: #facc15; color: #111; font-size: 7px; font-weight: 900; }
</style>
