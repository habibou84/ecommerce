<script setup>
import { computed } from 'vue'

/**
 * Copilote du live : ce qu'il faut faire et dire maintenant, avec la phrase prête et le bouton qui va avec.
 * Calculé sur le téléphone avec ce que le cockpit reçoit déjà (commandes, pièces, liste d'attente, questions,
 * état du show) : aucune question de plus au serveur. Les 4 conseils les plus importants d'abord.
 */
const props = defineProps({
  live: { type: Object, required: true },
  products: { type: Array, required: true },
  current: { type: Object, default: null },
  next: { type: Object, default: null },
  state: { type: Object, default: null }, // état du show : défi, dernière chance, annonce, mystère
  orders: { type: Number, default: 0 },
  questions: { type: Array, default: () => [] },
  now: { type: Number, required: true },
  plan: { type: Object, required: true }, // useLivePlan
  lastAt: { type: Function, required: true }, // dernier lancement d'un moment (ms)
  featuredSince: { type: Number, default: 0 },
  soldAtFeature: { type: Number, default: 0 },
  shownCodes: { type: Array, default: () => [] },
  withPlan: { type: Boolean, default: true }, // le plan est déjà affiché au-dessus : pas de conseil « prochaine étape »
})
const emit = defineEmits(['act'])

const MIN = 60000
const fmt = (ms) => { const s = Math.max(0, Math.round(ms / 1000)); return s < 60 ? `${s} s` : `${Math.floor(s / 60)} min` }

const cards = computed(() => {
  const C = []
  const add = (pr, card) => C.push({ pr, ...card })
  const now = props.now
  const started = props.live.started_at ? new Date(props.live.started_at).getTime() : now
  const minutes = (now - started) / MIN
  const state = props.state ?? {}
  const current = props.current
  const recent = props.live.reservations.filter((r) => ['reserved', 'ordered'].includes(r.status))
  const lastOrder = Math.max(started, ...recent.map((r) => new Date(r.created_at).getTime()))
  const rush = recent.filter((r) => now - new Date(r.created_at).getTime() < 90000).length
  const cool = (type, min = 10) => now - props.lastAt(type) > min * MIN
  const mysteryWaiting = props.products.find((p) => p.mystery && !p.revealed)
  const shown = props.products.filter((p) => !p.mystery || p.revealed)

  // Article mystère en cours : indice ou révélation
  if (state.mystery) {
    add(100, { cls: 'hot', title: `🎁 Mystère en cours · indice ${state.mystery.level}/3`, say: state.mystery.level < 3 ? 'Qui a trouvé ? Écrivez votre réponse en commentaire !' : 'Dernière chance de deviner… je révèle !',
      acts: state.mystery.level < 3 ? [['💡 Indice', { kind: 'moment', type: 'hint' }, true], ['✨ Révéler', { kind: 'moment', type: 'reveal' }]] : [['✨ Révéler', { kind: 'moment', type: 'reveal' }, true]] })
  }
  // Question d'une cliente
  const q = props.questions[0]
  if (q) {
    const age = now - new Date(q.created_at).getTime()
    add(age > MIN ? 95 : 80, { cls: 'hot', title: `❓ ${q.name} : « ${q.body} »${age > MIN ? ` · sans réponse depuis ${fmt(age)}` : ''}`, say: 'Bonne question ! Je vous réponds tout de suite.',
      acts: [['📺 Montrer à l\'écran', { kind: 'question', message: q }, true], ['Répondre', { kind: 'tab', tab: 'messages' }]] })
  }
  // Fin prévue proche
  const end = props.plan.plannedEnd.value
  const lastOn = state.last_until && new Date(state.last_until).getTime() > now
  if (end && end - now < 5 * MIN && end > now && !lastOn) {
    add(90, { cls: 'hot', title: `⏰ Fin prévue dans ${fmt(end - now)}`, say: 'Plus que quelques minutes, c\'est le moment ou jamais !', acts: [['⏳ Dernière chance', { kind: 'moment', type: 'last' }, true]] })
  }
  // Dernières pièces, épuisé, liste d'attente
  if (current && current.remaining > 0 && current.remaining <= 2) {
    add(88, { cls: 'hot', title: `🔥 Plus que ${current.remaining} ${current.code} !`, say: `Il n'en reste que ${current.remaining}, après c'est fini. Envoyez ${current.code} maintenant !`,
      acts: [...(cool('podium') ? [['🥇 Les 3 premiers', { kind: 'moment', type: 'podium' }]] : []), ['⚡ Vente flash', { kind: 'flash', item: current.items[0] }]] })
  }
  if (current && current.remaining === 0) {
    add(86, { cls: 'hot', title: `⛔ ${current.code} épuisé${current.waiting ? ` · ${current.waiting} en liste d'attente` : ''}`, say: 'Envoyez quand même le code, je vous rappelle dès que ça revient.',
      acts: props.next ? [[`Montrer ${props.next.code} ▶`, { kind: 'feature', product: props.next }, true]] : [] })
  }
  for (const size of current?.sizes ?? []) {
    const waiting = current.items.find((i) => i.variant === size.name)?.waiting ?? 0
    if (size.remaining === 0 && waiting >= 3) {
      add(70, { title: `📝 ${waiting} personnes attendent ${current.code} en ${size.name}`, say: `La ${size.name} est partie, mais j'ai d'autres tailles et couleurs : envoyez ${current.code} + votre taille !`, acts: [] })
      break
    }
  }
  // Article mystère annoncé : c'est l'heure
  if (state.announce_at && !state.mystery && new Date(state.announce_at).getTime() - now < 30000) {
    add(87, { cls: 'ok', title: '🎁 C\'est l\'heure de l\'article mystère annoncé', say: 'Vous êtes prêts ? On découvre l\'article mystère !', acts: [['🎁 Lancer', { kind: 'moment', type: 'mystery' }, true]] })
  }
  // Défi presque atteint
  if (state.defi && state.defi.target - props.orders > 0 && state.defi.target - props.orders <= 3) {
    const left = state.defi.target - props.orders
    add(75, { cls: 'ok', title: `🎯 Plus que ${left} commande${left > 1 ? 's' : ''} pour le défi`, say: `Encore ${left} et c'est ${state.defi.reward.toLowerCase()} !`, acts: [['🎁 Réaction', { kind: 'moment', type: 'react', body: { emoji: '🎁' } }]] })
  }
  // Ça s'enchaîne
  if (rush >= 3) {
    add(72, { cls: 'ok', title: `🚀 ${rush} commandes en 1 min 30 : ça s'enchaîne !`, say: 'Merci à tous ! Allez, qui est le suivant ?',
      acts: [cool('podium') ? ['🥇 Les 3 premiers', { kind: 'moment', type: 'podium' }] : ['🔥 Flammes', { kind: 'moment', type: 'react', body: { emoji: '🔥' } }], ...(!state.defi && cool('defi', 15) ? [['🎯 Défi', { kind: 'tab', tab: 'moments' }]] : [])] })
  }
  // Ça ralentit
  if (minutes > 3 && now - lastOrder > 4 * MIN && !state.mystery) {
    const acts = []
    if (mysteryWaiting && cool('mystery', 15)) acts.push(['🎁 Article mystère', { kind: 'moment', type: 'mystery' }, true])
    if (current?.remaining) acts.push(['⚡ Vente flash', { kind: 'flash', item: current.items[0] }, !acts.length])
    if (cool('wheel', 15) && recent.some((r) => r.status === 'ordered')) acts.push(['🎡 Roue', { kind: 'moment', type: 'wheel' }])
    add(78, { title: `😴 Ça ralentit : aucune commande depuis ${fmt(now - lastOrder)}`, say: 'Restez avec moi, j\'ai une surprise pour vous…', acts })
  }
  // Article qui ne part pas : passer à celui qui se vend le mieux
  if (current && current.remaining > 0 && now - props.featuredSince > 5 * MIN && current.sold - props.soldAtFeature <= 1) {
    const best = shown.filter((p) => p.code !== current.code && p.remaining > 0).sort((a, b) => (b.sold + b.waiting) - (a.sold + a.waiting))[0]
    if (best) add(60, { title: `⏱ ${current.code} depuis ${fmt(now - props.featuredSince)}, ${current.sold - props.soldAtFeature} vente : passe à ${best.code}`, say: `Je vous montre la ${best.code}, elle part vite !`, acts: [[`Montrer ${best.code} ▶`, { kind: 'feature', product: best }, true]] })
  }
  // Article jamais montré
  const never = shown.find((p) => p.remaining > 0 && !props.shownCodes.includes(p.code))
  if (never && minutes > 15) add(40, { title: `👀 Tu n'as pas encore montré ${never.code}`, say: `Regardez celle-ci : ${never.title} !`, acts: [[`Montrer ${never.code}`, { kind: 'feature', product: never }]] })
  // Annoncer le mystère pour garder les gens
  if (mysteryWaiting && !state.announce_at && !state.mystery && minutes >= 2) {
    add(50, { title: '📣 Annonce l\'article mystère pour garder les gens', say: 'Restez jusqu\'au bout : un article mystère arrive dans quelques minutes !', acts: [['📣 Annoncer (5 min)', { kind: 'moment', type: 'announce' }, true]] })
  }
  // Commandes à confirmer
  const toConfirm = new Set(props.live.reservations.filter((r) => r.order?.status === 'pending_confirmation').map((r) => r.order.id)).size
  if (toConfirm >= 3) add(55, { title: `✅ ${toConfirm} commandes à confirmer`, say: '', acts: [['Voir les commandes', { kind: 'tab', tab: 'commandes' }]] })
  // Plan du live
  const step = props.plan.next.value
  if (props.withPlan && step && !state.mystery) add(45, { title: `📋 Prochaine étape : ${step.label}`, say: step.say ?? '', acts: [['Faire ▶', { kind: 'plan' }]] })
  // Les moments ne sont plus dans le plan : le copilote les propose au bon moment
  if (!state.defi && cool('defi', 20) && minutes > 10 && rush < 3 && now - lastOrder < 4 * MIN && recent.length >= 3) {
    add(42, { title: '🎯 Lance un défi pour faire monter les commandes', say: 'Encore 5 commandes et c\'est la livraison offerte pour tout le monde !', acts: [['🎯 Défi : 5 commandes', { kind: 'moment', type: 'defi', body: { goal: 5, reward: 'delivery' } }, true]] })
  }
  if (cool('podium', 20) && current && current.remaining >= 3 && now - props.featuredSince > 2 * MIN && now - props.featuredSince < 4 * MIN) {
    add(38, { title: `🥇 Les 3 premiers sur la ${current.code}`, say: `Les 3 premiers à commander la ${current.code} ont un cadeau !`, acts: [['🥇 Lancer', { kind: 'moment', type: 'podium' }, true]] })
  }
  if (minutes > 25 && cool('wheel', 25) && recent.filter((r) => r.status === 'ordered').length >= 3) {
    add(36, { title: '🎡 Fais tourner la roue parmi les acheteurs', say: 'Une personne qui a commandé ce soir va gagner sa livraison !', acts: [['🎡 Roue', { kind: 'moment', type: 'wheel' }, true]] })
  }
  if (minutes > 20 && cool('map', 25)) {
    add(33, { title: '🗺️ Montre d\'où viennent les commandes', say: 'Regardez, ça commande de partout ! Quel quartier va gagner ?', acts: [['🗺️ Carte', { kind: 'moment', type: 'map' }]] })
  }
  // Rappels
  if (minutes > 3 && cool('how', 5)) add(35, { title: '💬 Pense aux nouveaux arrivés', say: 'Pour commander : regardez le code de l\'article et envoyez-le au numéro de la bio.', acts: [['💬 Comment commander', { kind: 'moment', type: 'how' }]] })
  if (minutes > 8 && Math.floor(minutes / 10) % 2 === 1) add(30, { title: '🚚 Rappelle la livraison et le paiement', say: 'On vous livre partout, et vous payez à la livraison ou par Mobile Money.', acts: [] })
  if (!C.length) add(1, { cls: 'ok', title: '👍 Tout va bien', say: current ? `Présente bien la ${current.code} : la matière, les tailles, comment la porter.` : 'Montre ton premier article pour commencer.', acts: current ? [['❤️ Cœurs', { kind: 'moment', type: 'react', body: { emoji: '❤️' } }]] : [] })
  return C.sort((a, b) => b.pr - a.pr).slice(0, 3)
})
</script>

<template>
  <div class="space-y-2" aria-live="polite">
    <div v-for="c in cards" :key="c.title" class="rounded-2xl border p-3" :class="c.cls === 'hot' ? 'border-red-400/70 bg-red-500/10' : c.cls === 'ok' ? 'border-emerald-400/60 bg-emerald-500/10' : 'border-white/15 bg-white/5'">
      <p class="text-[14px] font-extrabold leading-snug">{{ c.title }}</p>
      <p v-if="c.say" class="mt-1 text-[13px] leading-snug text-amber-200">« {{ c.say }} »</p>
      <div v-if="c.acts.length" class="mt-2 flex flex-wrap gap-1.5">
        <button v-for="[label, action, main] in c.acts" :key="label" type="button" class="rounded-xl px-3 py-2 text-[13px] font-extrabold" :class="main ? 'bg-amber-400 text-slate-900' : 'bg-white/15 text-white'" @click="emit('act', action)">{{ label }}</button>
      </div>
    </div>
  </div>
</template>
