<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { errorMessage } from '../../lib/http'
import { money } from '../../lib/format'
import { credits, loadCredits } from '../../lib/credits'
import CountedList from '../../components/credits/CountedList.vue'
import RechargePicker from '../../components/credits/RechargePicker.vue'

/**
 * Mon solde : commandes et actions IA, ce qui est compté (payant ou gratuit), recharger (pack ou illimité 30 jours),
 * ce qui a été utilisé ce mois-ci, paiements. Retour de PayDunya : ?paiement=numéro, vérifié chez le prestataire.
 */
const route = useRoute()
const router = useRouter()
const notice = ref(null)

onMounted(async () => {
  const number = route.query.paiement
  if (number) {
    try {
      const { data } = await http.post(`/credits/${number}/confirm`)
      credits.data = data.data
      notice.value = data.data.status === 'paid' ? { ok: true, text: 'Paiement reçu : votre solde est rechargé. Merci !' } : { ok: false, text: 'Paiement pas encore confirmé. Réessayez dans quelques minutes.' }
    } catch (e) {
      notice.value = { ok: false, text: errorMessage(e) }
    }
    router.replace({ query: {} })
  } else {
    await loadCredits()
  }
})

const date = (iso) => (iso ? new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' }) : '')
const STATUS = { paid: 'payé', offered: 'offert', pending: 'en attente' }
</script>

<template>
  <div class="mx-auto max-w-xl space-y-3">
    <h1 class="font-display text-xl font-extrabold">💳 Mon solde</h1>
    <p v-if="notice" class="rounded-xl p-3 text-sm font-bold" :class="notice.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-900'">{{ notice.text }}</p>
    <p v-if="!credits.data" class="p-4 text-center text-sm text-slate-500">Chargement…</p>

    <template v-else>
      <section class="card space-y-2 p-4">
        <div class="grid grid-cols-2 gap-2">
          <div class="rounded-xl bg-slate-50 p-3">
            <b class="block font-display text-2xl tabular-nums" :class="credits.data.orders < 0 ? 'text-red-700' : ''">{{ credits.data.month_until ? '∞' : credits.data.orders }}</b>
            <span class="text-xs text-slate-500">commandes</span>
          </div>
          <div class="rounded-xl bg-slate-50 p-3"><b class="block font-display text-2xl tabular-nums">{{ credits.data.ai }}</b><span class="text-xs text-slate-500">actions IA</span></div>
        </div>
        <p v-if="credits.data.month_until" class="text-sm font-bold text-emerald-700">♾️ Illimité jusqu'au {{ new Date(credits.data.month_until).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' }) }}</p>
        <p v-else-if="credits.data.low" class="rounded-xl bg-ocre-50 p-2.5 text-sm font-bold text-ocre-800">⚠️ Plus que {{ credits.data.orders }} commandes : rechargez avant votre prochain live.</p>
        <p v-if="!credits.data.paid" class="text-sm text-slate-500">Pour l'instant, rien n'est compté : tout est gratuit.</p>
      </section>

      <section class="card p-4">
        <h3 class="font-display text-base font-extrabold">Ce qui est compté</h3>
        <CountedList class="mt-1" />
      </section>

      <section v-if="credits.data.paid" class="card space-y-2 p-4">
        <h3 class="font-display text-base font-extrabold">Recharger</h3>
        <RechargePicker :data="credits.data" />
      </section>

      <section v-if="credits.data.paid" class="card p-4">
        <h3 class="font-display text-base font-extrabold">Ce mois-ci</h3>
        <ul class="mt-1 divide-y divide-slate-100 text-sm">
          <li v-for="l in credits.data.usage.lives" :key="`l${l.label}${l.at}`" class="flex items-center gap-2 py-2">🔴 <span class="min-w-0 flex-1 truncate">{{ l.label }} <span class="text-xs text-slate-500">{{ date(l.at) }}</span></span><b class="tabular-nums">−{{ Math.abs(l.orders) }}</b></li>
          <li v-if="credits.data.usage.whatsapp" class="flex items-center gap-2 py-2">💬 <span class="flex-1">Commandes WhatsApp</span><b class="tabular-nums">−{{ credits.data.usage.whatsapp }}</b></li>
          <li v-for="b in credits.data.usage.boosts" :key="`b${b.label}`" class="flex items-center gap-2 py-2">🚀 <span class="min-w-0 flex-1 truncate">Boost {{ b.label }}</span><b class="tabular-nums">−{{ Math.abs(b.orders) }}</b></li>
          <li v-if="credits.data.usage.ai" class="flex items-center gap-2 py-2">✨ <span class="flex-1">Actions IA utilisées</span><b class="tabular-nums">{{ credits.data.usage.ai }}</b></li>
          <li v-if="!credits.data.usage.lives.length && !credits.data.usage.whatsapp && !credits.data.usage.boosts.length && !credits.data.usage.ai" class="py-2 text-slate-500">Rien d'utilisé ce mois-ci.</li>
        </ul>
      </section>

      <section v-if="credits.data.payments.length" class="card p-4">
        <h3 class="font-display text-base font-extrabold">Recharges</h3>
        <ul class="mt-1 divide-y divide-slate-100 text-sm">
          <li v-for="p in credits.data.payments" :key="p.number" class="flex items-center gap-2 py-2">
            <span class="min-w-0 flex-1"><span class="block truncate">{{ p.label }}</span><span class="text-xs text-slate-500">{{ date(p.paid_at || p.created_at) }} · {{ STATUS[p.status] ?? p.status }}</span></span>
            <b class="tabular-nums">{{ p.amount ? money(p.amount) : 'offert' }}</b>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>
