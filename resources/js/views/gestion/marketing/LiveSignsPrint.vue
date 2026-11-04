<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import http, { errorMessage } from '../../../lib/http'
import { money } from '../../../lib/format'
import { useAuthStore } from '../../../stores/auth'

/**
 * Pancartes du live à imprimer (le code en très grand, « Envoie A1 + ta taille », le nom de la boutique au choix) et
 * liste d'aide (code, article, tailles, prix, case à cocher). Sans numéro, QR code ni mot « WhatsApp » : rien qui
 * envoie hors de TikTok (modération). Modèle « Signature » : logo et couleur principale de la boutique.
 * Sans live : une série de pancartes à imprimer une fois (A1 à A9…) ; avec un live : ses pancartes et sa liste d'aide.
 * Page hors du menu de la gestion, pour que seules les feuilles partent à l'impression.
 */
const props = defineProps({ id: { type: [String, Number], default: null } })
const route = useRoute()
const auth = useAuthStore()

const view = ref(route.query.vue === 'liste' && props.id ? 'liste' : 'pancartes')
const live = ref(null)
const error = ref('')

const options = reactive({ format: 'grand', style: 'marque', shop: true, hint: true, live: true, letters: ['A'], upTo: 9 })
const FORMATS = {
  grand: { label: 'Grandes · 2 par feuille', detail: 'demi-feuille A4, code de 7 cm', per: 2, landscape: true },
  moyen: { label: 'Moyennes · 4 par feuille', detail: 'quart de feuille, code de 4,5 cm', per: 4, landscape: false },
  etiquette: { label: 'Étiquettes · 12 par feuille', detail: 'à accrocher au cintre ou au sac', per: 12, landscape: false },
}
const STYLES = { marque: 'Signature', blanc: 'Blanc', rouge: 'Rouge', jaune: 'Jaune' }
const LETTERS = 'ABCDEFGH'.split('')

// Logo, nom et couleur de la boutique (modèle « Signature »)
const brand = ref(null)
const color = computed(() => brand.value?.color ?? '#b91c1c')
// Couleur trop claire : code et bandeau en noir pour rester lisibles à la caméra
const light = computed(() => {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(color.value.slice(i, i + 2), 16))
  return 0.299 * r + 0.587 * g + 0.114 * b > 170
})
const initials = computed(() => (brand.value?.name ?? auth.store?.name ?? '').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase())
http.get('/lives/brand').then(({ data }) => { brand.value = data.data }).catch(() => {})

onMounted(async () => {
  if (!props.id) return
  try {
    live.value = (await http.get(`/lives/${props.id}`)).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
})

// Un produit = une pancarte : ses tailles et couleurs regroupées pour la liste d'aide
const products = computed(() => {
  const groups = new Map()
  for (const i of live.value?.items ?? []) {
    const g = groups.get(i.code) ?? { code: i.code, title: i.product ?? i.title, variants: [], prices: [], pieces: 0 }
    if (i.variant) g.variants.push(i.variant)
    g.prices.push(i.price)
    g.pieces += i.quantity
    groups.set(i.code, g)
  }
  return [...groups.values()]
})

function toggleLetter(l) {
  const index = options.letters.indexOf(l)
  if (index >= 0 && options.letters.length > 1) options.letters.splice(index, 1)
  else if (index < 0) options.letters.push(l)
}
// Pancarte « LIVE » en premier : la cliente écrit LIVE et reçoit tous les articles encore disponibles
const codes = computed(() => {
  const live = options.live ? ['LIVE'] : []
  if (props.id) return [...live, ...products.value.map((p) => p.code)]
  const upTo = Math.max(1, Math.min(Number(options.upTo) || 1, 30))
  return [...live, ...[...options.letters].sort().flatMap((l) => Array.from({ length: upTo }, (_, i) => `${l}${i + 1}`))]
})
const sheets = computed(() => {
  const per = FORMATS[options.format].per
  const list = []
  for (let i = 0; i < codes.value.length; i += per) list.push(codes.value.slice(i, i + per))
  return list
})
const shopName = computed(() => auth.store?.name ?? '')

// Taille du code selon la place et sa longueur (A1 ou B12)
const codeSize = (code) => ({ grand: [92, 64, 50], moyen: [62, 44, 34], etiquette: [38, 27, 20] })[options.format][Math.min(code.length, 4) - 2]

// Orientation de la feuille à l'impression : paysage pour les grandes pancartes (deux demi-feuilles côte à côte)
let pageStyle
function applyPage() {
  pageStyle ??= document.head.appendChild(document.createElement('style'))
  const landscape = view.value === 'pancartes' && FORMATS[options.format].landscape
  pageStyle.textContent = `@page { size: A4 ${landscape ? 'landscape' : 'portrait'}; margin: ${view.value === 'liste' ? '12mm' : '0'}; }`
}
watch([view, () => options.format], applyPage, { immediate: true })
onUnmounted(() => pageStyle?.remove())

const print = () => window.print()
</script>

<template>
  <div class="min-h-screen bg-slate-100 print:bg-white">
    <!-- Réglages (pas imprimés) -->
    <header class="no-print sticky top-0 z-10 border-b border-slate-200 bg-white">
      <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-3 px-4 py-3">
        <RouterLink :to="id ? `/gestion/live/${id}` : '/gestion/live'" class="text-sm font-semibold text-emerald-700">← Retour</RouterLink>
        <h1 class="min-w-0 flex-1 truncate font-extrabold">{{ id ? (live?.title ?? 'Live') : 'Pancartes du live' }}</h1>
        <button type="button" class="btn-brand" :disabled="view === 'pancartes' ? !codes.length : !products.length" @click="print">🖨 Imprimer</button>
      </div>
      <div v-if="id" class="mx-auto flex max-w-5xl gap-1 px-4 pb-2" role="tablist">
        <button v-for="[key, label] in [['pancartes', 'Pancartes'], ['liste', 'Liste d\'aide']]" :key="key" type="button" role="tab" :aria-selected="view === key"
                class="rounded-full px-4 py-1.5 text-sm font-bold" :class="view === key ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'" @click="view = key">{{ label }}</button>
      </div>
    </header>

    <main class="mx-auto max-w-5xl space-y-4 px-4 py-4 print:max-w-none print:p-0">
      <Alert v-if="error" class="no-print">{{ error }}</Alert>

      <template v-if="view === 'pancartes'">
        <section class="no-print card space-y-4 p-4 text-sm">
          <p class="text-slate-600">
            Imprimez sur du papier épais et mat (pas de reflet à la caméra), collez sur du carton ou plastifiez : les pancartes
            servent à chaque live. Tenez la pancarte près de l'article, à moins d'un mètre du téléphone.
          </p>
          <div>
            <p class="mb-1.5 font-semibold">Taille</p>
            <div class="grid gap-2 sm:grid-cols-3">
              <label v-for="(f, key) in FORMATS" :key="key" class="flex cursor-pointer items-start gap-2 rounded-xl border-2 p-2.5" :class="options.format === key ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200'">
                <input v-model="options.format" type="radio" :value="key" class="mt-0.5 accent-emerald-700" />
                <span><b class="block">{{ f.label }}</b><span class="text-xs text-slate-500">{{ f.detail }}</span></span>
              </label>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
            <div class="flex items-center gap-1.5">
              <span class="mr-1 font-semibold">Couleur</span>
              <button v-for="(label, key) in STYLES" :key="key" type="button" :aria-pressed="options.style === key" class="rounded-lg border-2 px-3 py-1.5 font-bold"
                      :class="[options.style === key ? 'border-slate-900' : 'border-slate-200', { blanc: 'bg-white', rouge: 'bg-red-700 text-white', jaune: 'bg-yellow-300' }[key]]"
                      :style="key === 'marque' ? { background: color, color: light ? '#111' : '#fff' } : null" @click="options.style = key">{{ label }}</button>
            </div>
            <label class="flex items-center gap-2"><input v-model="options.hint" type="checkbox" class="h-4 w-4 accent-emerald-700" />« Envoie A1 + ta taille »</label>
            <label class="flex items-center gap-2"><input v-model="options.live" type="checkbox" class="h-4 w-4 accent-emerald-700" />Pancarte « LIVE » (tous les articles)</label>
            <label v-if="options.format !== 'etiquette'" class="flex items-center gap-2"><input v-model="options.shop" type="checkbox" class="h-4 w-4 accent-emerald-700" />Nom de la boutique</label>
          </div>
          <div v-if="!id" class="flex flex-wrap items-center gap-x-6 gap-y-3">
            <div class="flex flex-wrap items-center gap-1.5">
              <span class="mr-1 font-semibold">Séries</span>
              <button v-for="l in LETTERS" :key="l" type="button" :aria-pressed="options.letters.includes(l)" class="h-9 w-9 rounded-lg text-sm font-black"
                      :class="options.letters.includes(l) ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'" @click="toggleLetter(l)">{{ l }}</button>
            </div>
            <label class="flex items-center gap-2"><span class="font-semibold">De 1 à</span><input v-model="options.upTo" type="number" min="1" max="30" class="input w-20 py-1" /></label>
            <span class="text-slate-500">{{ codes.length }} pancarte{{ codes.length > 1 ? 's' : '' }} · {{ sheets.length }} feuille{{ sheets.length > 1 ? 's' : '' }}</span>
          </div>
          <p v-else class="text-slate-500">Les pancartes de ce live : {{ codes.join(', ') || '…' }} · {{ sheets.length }} feuille{{ sheets.length > 1 ? 's' : '' }}.</p>
        </section>

        <!-- Feuilles A4 (aperçu réduit à l'écran, taille réelle à l'impression) -->
        <div class="sheets">
          <div v-for="(sheet, s) in sheets" :key="s" class="sheet" :class="[`f-${options.format}`, FORMATS[options.format].landscape ? 'landscape' : 'portrait']">
            <!-- Signature : bandeau logo + nom en haut, code à la couleur de la boutique, consigne dans un bandeau en bas -->
            <template v-if="options.style === 'marque'">
            <div v-for="code in sheet" :key="`m-${code}`" class="sign s-marque" :style="{ '--c': color, '--on': light ? '#111' : '#fff', '--code': light ? '#111' : color }">
              <div v-if="options.shop || options.format === 'etiquette'" class="m-top">
                <img v-if="brand?.logo" :src="brand.logo" alt="" class="m-logo" />
                <span v-else class="m-logo m-initials">{{ initials }}</span>
                <b v-if="options.format !== 'etiquette' && shopName">{{ shopName }}</b>
              </div>
              <span class="m-code" :style="{ fontSize: `${codeSize(code)}mm` }">{{ code }}</span>
              <div v-if="options.hint" class="m-bot">
                <b>{{ code === 'LIVE' ? 'Envoie LIVE' : `Envoie ${code}` }}</b>
                <span v-if="options.format !== 'etiquette'">{{ code === 'LIVE' ? 'pour voir tous les articles' : `+ ta taille · ex. ${code} M` }}</span>
              </div>
            </div>
            </template>
            <template v-else>
            <div v-for="code in sheet" :key="code" class="sign" :class="`s-${options.style}`">
              <span v-if="options.shop && options.format !== 'etiquette' && shopName" class="shop">{{ shopName }}</span>
              <span class="code" :style="{ fontSize: `${codeSize(code)}mm` }">{{ code }}</span>
              <span v-if="options.hint" class="hint">{{ code === 'LIVE' ? 'Envoie LIVE pour tout voir' : `Envoie ${code} + ta taille` }}</span>
            </div>
            </template>
          </div>
        </div>
      </template>

      <!-- Liste d'aide : à garder à côté de soi pendant le live -->
      <section v-else class="aid card p-4 print:border-0 print:p-0 print:shadow-none">
        <h2 class="text-lg font-extrabold">{{ live?.title }} <span class="font-semibold text-slate-500">· {{ shopName }}</span></h2>
        <p class="text-sm text-slate-500">À garder à côté de vous. Cochez quand l'article est présenté.</p>
        <ul class="mt-3 divide-y divide-slate-200 border-t border-slate-200">
          <li v-for="p in products" :key="p.code" class="flex items-center gap-3 py-2.5">
            <span class="h-5 w-5 shrink-0 rounded border-2 border-slate-400" aria-hidden="true" />
            <span class="grid h-11 w-16 shrink-0 place-items-center rounded-lg border-[3px] border-slate-900 text-xl font-black">{{ p.code }}</span>
            <span class="min-w-0 flex-1">
              <b class="block">{{ p.title }}</b>
              <span class="text-sm text-slate-500">{{ p.variants.length ? p.variants.join(' · ') : 'taille unique' }} · {{ p.pieces }} pièce{{ p.pieces > 1 ? 's' : '' }}</span>
            </span>
            <b class="shrink-0 whitespace-nowrap">{{ Math.min(...p.prices) === Math.max(...p.prices) ? money(p.prices[0]) : `dès ${money(Math.min(...p.prices))}` }}</b>
          </li>
        </ul>
        <p v-if="live && !products.length" class="mt-3 text-sm text-slate-500">Aucun article dans ce live.</p>
      </section>
    </main>
  </div>
</template>

<style scoped>
.sheets { display: flex; flex-direction: column; align-items: center; gap: 16px; }
.sheet { background: #fff; display: grid; box-shadow: 0 6px 20px rgba(15, 23, 42, .18); overflow: hidden; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.sheet.landscape { width: 297mm; height: 210mm; }
.sheet.portrait { width: 210mm; height: 297mm; }
.f-grand { grid-template-columns: repeat(2, 1fr); grid-template-rows: 1fr; }
.f-moyen { grid-template-columns: repeat(2, 1fr); grid-template-rows: repeat(2, 1fr); }
.f-etiquette { grid-template-columns: repeat(3, 1fr); grid-template-rows: repeat(4, 1fr); }
.sign { position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; outline: .3mm dashed #cbd5e1; outline-offset: -.15mm; font-family: Inter, system-ui, sans-serif; }
.code { font-weight: 900; line-height: .9; letter-spacing: -.04em; }
.shop { position: absolute; top: 6%; left: 0; right: 0; text-align: center; font-weight: 700; font-size: 6mm; opacity: .7; }
.hint { position: absolute; bottom: 6%; left: 0; right: 0; text-align: center; font-weight: 800; font-size: 8mm; }
.f-moyen .shop { font-size: 4.5mm; } .f-moyen .hint { font-size: 5.5mm; }
.f-etiquette .hint { font-size: 3.4mm; bottom: 7%; }
.f-etiquette .sign::before { content: ''; position: absolute; top: 6mm; left: 50%; width: 5mm; height: 5mm; margin-left: -2.5mm; border-radius: 50%; border: .4mm solid #94a3b8; background: #fff; }
.s-blanc { background: #fff; color: #111; }
/* Signature : couleurs de la boutique */
.s-marque { justify-content: space-between; background: #fff; color: #111; }
.m-top { width: 100%; display: flex; align-items: center; gap: 4mm; padding: 7mm 9mm 5mm; border-bottom: 1.6mm solid var(--c); }
.m-logo { width: 15mm; height: 15mm; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
.m-initials { display: grid; place-items: center; background: var(--c); color: var(--on); font: 900 6mm Georgia, serif; }
.m-top b { font-size: 6.5mm; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.m-code { flex: 1; display: grid; place-items: center; color: var(--code); font-weight: 900; line-height: .9; letter-spacing: -.04em; }
.m-bot { width: 100%; background: var(--c); color: var(--on); text-align: center; padding: 6mm 4mm 7mm; }
.m-bot b { display: block; font-size: 10mm; font-weight: 900; line-height: 1.1; }
.m-bot span { display: block; font-size: 5mm; font-weight: 600; opacity: .9; margin-top: 1mm; }
.f-moyen .m-top { padding: 4mm 6mm 3mm; gap: 3mm; } .f-moyen .m-logo { width: 10mm; height: 10mm; } .f-moyen .m-initials { font-size: 4mm; }
.f-moyen .m-top b { font-size: 4.5mm; } .f-moyen .m-bot { padding: 3.5mm 3mm 4mm; } .f-moyen .m-bot b { font-size: 7mm; } .f-moyen .m-bot span { font-size: 3.5mm; }
.f-etiquette .m-top { justify-content: center; padding: 12.5mm 3mm 1.5mm; border-bottom-width: .8mm; } .f-etiquette .m-logo { width: 7mm; height: 7mm; } .f-etiquette .m-initials { font-size: 3mm; }
.f-etiquette .m-bot { padding: 2mm; } .f-etiquette .m-bot b { font-size: 4.5mm; }
.s-rouge { background: #b91c1c; color: #fff; } .s-rouge .hint { color: #fde047; }
.s-jaune { background: #fde047; color: #111; }
.f-grand .s-jaune, .f-moyen .s-jaune { box-shadow: inset 0 0 0 6mm #fde047, inset 0 0 0 8mm #111; }

/* Aperçu réduit à l'écran */
@media screen {
  .sheet.landscape { zoom: .32; }
  .sheet.portrait { zoom: .4; }
  @media (min-width: 640px) { .sheet.landscape { zoom: .55; } .sheet.portrait { zoom: .55; } }
}
@media print {
  .no-print { display: none !important; }
  .sheets { display: block; }
  .sheet { box-shadow: none; zoom: 1; break-after: page; page-break-after: always; }
  .sheet:last-child { break-after: auto; page-break-after: auto; }
  .aid { font-size: 12pt; }
}
</style>
