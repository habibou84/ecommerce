<script setup>
/**
 * « Comment ça marche » du live (avant « Préparer mon live ») : le problème, la solution en 4 étapes, ce qu'on gagne.
 * Inspiré de l'écran live de Jibiat Livraison. En entier aux 2 premières visites, replié ensuite (dans « Préparer »).
 */
defineProps({ shop: { type: String, default: '' } })

const comments = [['Awa', 'je prends la robe'], ['Mariam', 'moi aussi taille M !!'], ['Fatou', 'c\'est laquelle ?'], ['Kadi', 'dispo encore ?']]
const problems = [
  ['💬', 'Répondre à chaque cliente', 'Pendant que vous parlez, les messages s\'empilent.'],
  ['📝', 'Noter à la main', 'Les messages défilent, vous en ratez.'],
  ['❓', '« Laquelle ? Quelle taille ? »', 'Les clientes se trompent d\'article, vous perdez du temps.'],
  ['📦', 'Vendu deux fois', 'Un article épuisé est promis à plusieurs clientes.'],
  ['🌙', 'Le soir, tout recopier', 'Chaque commande et chaque livraison, une par une.'],
]
const gains = [
  ['🎤', 'Vous restez devant la caméra', 'Vous présentez vos articles, l\'application prend les commandes.'],
  ['🔔', 'Le stock en direct', '« Il en reste 2 », « Épuisé » : vous le dites au bon moment.'],
  ['⏳', 'La liste d\'attente', 'Article épuisé ? La cliente est notée et prévenue s\'il se libère.'],
  ['📺', 'La télé et TikTok Studio', 'L\'article présenté et son code s\'affichent tout seuls à l\'écran.'],
]
</script>

<template>
  <div class="space-y-3.5">
    <!-- Titre -->
    <div class="rounded-[24px] bg-gradient-to-br from-ocre-800 via-ocre-600 to-orange-400 p-5 text-white">
      <p class="text-[12px] font-extrabold uppercase tracking-wider text-orange-100">🎥 <template v-if="shop">{{ shop }} · </template>Comment ça marche</p>
      <h2 class="my-1.5 text-[24px] font-extrabold leading-tight">Vendez plus en live, sans perdre une seule commande</h2>
      <p class="text-[16px] text-orange-50">Vous montrez vos articles. Les clientes envoient le code, la commande se crée toute seule.</p>
      <p class="mt-3 rounded-2xl bg-white px-3.5 py-2.5 text-[17px] font-extrabold leading-normal text-ocre-700">✅ Vous n'avez rien à répondre.<br>✅ Vous n'avez rien à noter.</p>
    </div>

    <!-- Le problème -->
    <section class="rounded-[22px] bg-white p-4 shadow-soft">
      <span class="mb-2 inline-block rounded-full bg-red-50 px-2.5 py-1 text-[12px] font-extrabold text-red-800">😩 LE PROBLÈME</span>
      <h3 class="mb-2.5 text-[19px] font-extrabold">Pendant un live, tout va trop vite</h3>
      <div class="space-y-1.5 rounded-2xl bg-slate-900 p-2.5 text-[13px] text-white" aria-hidden="true">
        <div v-for="[n, t] in comments" :key="n" class="rounded-xl bg-slate-800 px-2.5 py-1.5"><b>{{ n }}</b> <span class="text-slate-400">{{ t }}</span></div>
      </div>
      <div v-for="[icon, title, text] in problems" :key="title" class="my-2.5 flex items-start gap-2.5">
        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-red-50 text-[22px]" aria-hidden="true">{{ icon }}</span>
        <div><b class="block text-[16px]">{{ title }}</b><p class="mt-0.5 text-[15px] text-slate-600">{{ text }}</p></div>
      </div>
    </section>

    <!-- La solution -->
    <section class="rounded-[22px] bg-white p-4 shadow-soft">
      <span class="mb-2 inline-block rounded-full bg-indigo-50 px-2.5 py-1 text-[12px] font-extrabold text-indigo-800">✅ LA SOLUTION EN 4 ÉTAPES</span>
      <h3 class="text-[19px] font-extrabold">Jibiat Marchand fait le travail pour vous</h3>
      <div class="my-3.5 flex items-center gap-3">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-900 text-[16px] font-extrabold text-white">1</span>
        <div class="h-[84px] w-[104px] shrink-0 overflow-hidden rounded-2xl border border-slate-200 bg-white p-2 text-[12px] leading-[1.7]" aria-hidden="true">☑️ Robe wax<br>☑️ Sac tressé<br>☐ Parfum</div>
        <div class="min-w-0"><b class="block text-[16px] leading-tight">Choisissez les articles du live</b><span class="text-[14px] text-slate-600">Chacun reçoit un code : A1, A2, A3…</span></div>
      </div>
      <div class="my-3.5 flex items-center gap-3">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-900 text-[16px] font-extrabold text-white">2</span>
        <div class="grid h-[84px] w-[104px] shrink-0 grid-cols-3 gap-1 overflow-hidden rounded-2xl bg-slate-900 p-1.5" aria-hidden="true">
          <div v-for="c in ['A1', 'A2', 'A3', 'A4', 'A5', 'A6']" :key="c" class="relative rounded bg-orange-200"><em class="absolute bottom-0.5 left-0.5 rounded-sm bg-white px-0.5 text-[9px] font-extrabold not-italic text-slate-900">{{ c }}</em></div>
        </div>
        <div class="min-w-0"><b class="block text-[16px] leading-tight">La photo à épingler est prête</b><span class="text-[14px] text-slate-600">Tous vos articles et leurs codes sur une seule image, faite automatiquement.</span></div>
      </div>
      <div class="my-3.5 flex items-center gap-3">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-900 text-[16px] font-extrabold text-white">3</span>
        <div class="h-[84px] w-[104px] shrink-0 overflow-hidden rounded-2xl bg-[#e7f8ee] p-2 text-[12px]" aria-hidden="true">
          <div class="w-3/4 rounded-lg bg-white px-2 py-1">A2 taille M</div>
          <div class="ml-auto mt-1.5 w-max whitespace-nowrap rounded-lg bg-[#25d366] px-2 py-1 text-[10px] text-white">✅ Commande notée !</div>
        </div>
        <div class="min-w-0"><b class="block text-[16px] leading-tight">Les clientes envoient le code</b><span class="text-[14px] text-slate-600">Sur WhatsApp. La réponse part toute seule : <b class="text-slate-900">vous n'avez rien à répondre.</b></span></div>
      </div>
      <div class="my-3.5 flex items-center gap-3">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-900 text-[16px] font-extrabold text-white">4</span>
        <div class="grid h-[84px] w-[104px] shrink-0 place-items-center overflow-hidden rounded-2xl bg-emerald-50 text-[40px]" aria-hidden="true">🛵</div>
        <div class="min-w-0"><b class="block text-[16px] leading-tight">La commande part au livreur</b><span class="text-[14px] text-slate-600">Avec le nom, le numéro et le quartier. <b class="text-slate-900">Vous n'enregistrez rien.</b></span></div>
      </div>
    </section>

    <!-- Ce que vous gagnez -->
    <section class="rounded-[22px] bg-white p-4 shadow-soft">
      <span class="mb-2 inline-block rounded-full bg-emerald-50 px-2.5 py-1 text-[12px] font-extrabold text-emerald-800">💪 CE QUE VOUS GAGNEZ</span>
      <h3 class="mb-2.5 text-[19px] font-extrabold">Vous vendez, l'application s'occupe du reste</h3>
      <div v-for="t in ['message à répondre', 'commande à enregistrer', 'commande oubliée']" :key="t" class="my-2.5 flex items-center gap-3.5 rounded-[18px] border-2 border-emerald-500 bg-emerald-50 px-4 py-3">
        <b class="min-w-[44px] text-center text-[40px] font-black leading-none text-emerald-700">0</b><span class="text-[19px] font-extrabold text-emerald-900">{{ t }}</span>
      </div>
      <div class="my-2.5 flex items-center gap-3.5 rounded-[18px] border-2 border-blue-500 bg-blue-50 px-4 py-3">
        <b class="min-w-[44px] text-center text-[30px] font-black leading-none">📊</b><span class="text-[19px] font-extrabold text-blue-900">le bilan de votre live, tout prêt</span>
      </div>
      <div v-for="[icon, title, text] in gains" :key="title" class="my-2.5 flex items-start gap-2.5">
        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-emerald-50 text-[22px]" aria-hidden="true">{{ icon }}</span>
        <div><b class="block text-[16px]">{{ title }}</b><p class="mt-0.5 text-[15px] text-slate-600">{{ text }}</p></div>
      </div>
    </section>
  </div>
</template>
