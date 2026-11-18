<script setup>
// Illustrations de l'assistant du premier accès (écran « Bienvenue ») : dessins vectoriels légers aux couleurs de
// la marque, décoratifs (cachés aux lecteurs d'écran)
defineProps({ name: { type: String, required: true } })
</script>

<template>
  <!-- La commerçante qui salue -->
  <svg v-if="name === 'hello'" viewBox="0 0 150 190" aria-hidden="true">
    <ellipse cx="75" cy="186" rx="52" ry="5" fill="#d1fadf" />
    <path d="M44 140 C30 120 24 100 30 78" fill="none" stroke="#8d5524" stroke-width="12" stroke-linecap="round" />
    <circle cx="31" cy="71" r="9" fill="#8d5524" />
    <path d="M14 60 q-5 9 0 18 M20 50 q-7 12 -2 24" fill="none" stroke="#039a45" stroke-width="3" stroke-linecap="round" />
    <path d="M106 142 C116 152 120 160 118 168" fill="none" stroke="#8d5524" stroke-width="12" stroke-linecap="round" />
    <path d="M35 190 C35 140 50 122 75 122 C100 122 115 140 115 190 Z" fill="#f2b632" />
    <circle cx="44" cy="140" r="11" fill="#f2b632" />
    <circle cx="106" cy="141" r="11" fill="#f2b632" />
    <circle cx="60" cy="152" r="3.5" fill="#d4500f" />
    <circle cx="90" cy="146" r="3.5" fill="#d4500f" />
    <circle cx="76" cy="170" r="3.5" fill="#d4500f" />
    <circle cx="52" cy="176" r="3.5" fill="#d4500f" />
    <circle cx="100" cy="174" r="3.5" fill="#d4500f" />
    <circle cx="74" cy="148" r="2" fill="#017c40" />
    <circle cx="64" cy="166" r="2" fill="#017c40" />
    <circle cx="88" cy="162" r="2" fill="#017c40" />
    <path d="M66 124 L75 136 L84 124 Z" fill="#7a4a1f" />
    <rect x="67" y="106" width="16" height="20" fill="#7a4a1f" />
    <path d="M111 164 q0 -12 8 -12 q8 0 8 12" fill="none" stroke="#017c40" stroke-width="3" />
    <rect x="104" y="162" width="32" height="26" rx="4" fill="#039a45" />
    <rect x="112" y="172" width="16" height="8" rx="2" fill="#a6f3c2" />
    <circle cx="118" cy="166" r="6" fill="#8d5524" />
    <circle cx="75" cy="88" r="26" fill="#8d5524" />
    <path d="M48 86 C44 56 62 44 76 44 C92 44 108 56 102 86 C96 72 86 66 75 66 C64 66 54 72 48 86 Z" fill="#039a45" />
    <ellipse cx="90" cy="42" rx="13" ry="9" fill="#017c40" transform="rotate(20 90 42)" />
    <ellipse cx="66" cy="41" rx="11" ry="8" fill="#039a45" transform="rotate(-20 66 41)" />
    <circle cx="62" cy="60" r="2.2" fill="#d1fadf" />
    <circle cx="78" cy="55" r="2.2" fill="#d1fadf" />
    <circle cx="93" cy="61" r="2.2" fill="#d1fadf" />
    <circle cx="49" cy="97" r="3" fill="#f2b632" />
    <circle cx="101" cy="97" r="3" fill="#f2b632" />
    <circle cx="66" cy="89" r="3" fill="#1f1206" />
    <circle cx="84" cy="89" r="3" fill="#1f1206" />
    <path d="M65 99 Q75 108 85 99" fill="none" stroke="#1f1206" stroke-width="2.5" stroke-linecap="round" />
    <circle cx="59" cy="97" r="4" fill="#b5683a" />
    <circle cx="91" cy="97" r="4" fill="#b5683a" />
  </svg>

  <!-- Fond de la carte d'accueil : ronds, feuilles et étoiles vertes -->
  <svg v-else-if="name === 'hello-bg'" viewBox="0 0 350 250" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
    <circle cx="330" cy="10" r="70" fill="#ecfdf1" />
    <circle cx="40" cy="240" r="80" fill="#ecfdf1" />
    <circle cx="300" cy="230" r="22" fill="#d1fadf" />
    <path d="M250 40 q14 -18 30 -6 q-12 16 -30 6 z" fill="#a6f3c2" />
    <path d="M318 120 q18 -8 24 10 q-18 6 -24 -10 z" fill="#a6f3c2" />
    <path d="M170 22 l3 7 l7 3 l-7 3 l-3 7 l-3 -7 l-7 -3 l7 -3 z" fill="#039a45" />
    <path d="M226 210 l2 5 l5 2 l-5 2 l-2 5 l-2 -5 l-5 -2 l5 -2 z" fill="#f2b632" />
  </svg>

  <!-- Livreur à scooter vers le client -->
  <svg v-else-if="name === 'delivery'" viewBox="0 0 350 150" aria-hidden="true">
    <circle cx="70" cy="30" r="34" fill="#d1fadf" />
    <circle cx="232" cy="22" r="16" fill="#d1fadf" />
    <rect x="0" y="120" width="350" height="30" fill="#cbd5e1" />
    <path d="M0 136 H350" stroke="#ffffff" stroke-width="3" stroke-dasharray="14 10" />
    <path d="M40 88 H80 M52 100 H86 M60 76 H84" stroke="#a6f3c2" stroke-width="4" stroke-linecap="round" />
    <rect x="100" y="58" width="36" height="32" rx="3" fill="#d4500f" />
    <rect x="116" y="58" width="4" height="32" fill="#ffe2cc" />
    <path d="M140 98 L146 62 H166 L172 98 Z" fill="#f2b632" />
    <path d="M162 70 L180 64" stroke="#8d5524" stroke-width="7" stroke-linecap="round" />
    <circle cx="156" cy="50" r="11" fill="#8d5524" />
    <path d="M144 49 A12 12 0 0 1 168 49 Z" fill="#063b24" />
    <path d="M184 104 L176 64" stroke="#063b24" stroke-width="5" stroke-linecap="round" />
    <path d="M171 62 H186" stroke="#063b24" stroke-width="5" stroke-linecap="round" />
    <path d="M110 98 H182 Q196 98 200 112 L202 118 H98 Q98 98 110 98 Z" fill="#039a45" />
    <circle cx="198" cy="104" r="4" fill="#f2b632" />
    <circle cx="118" cy="120" r="14" fill="#063b24" /><circle cx="118" cy="120" r="5" fill="#cbd5e1" />
    <circle cx="190" cy="120" r="14" fill="#063b24" /><circle cx="190" cy="120" r="5" fill="#cbd5e1" />
    <path d="M218 108 Q248 100 270 94" fill="none" stroke="#d4500f" stroke-width="3" stroke-dasharray="2 7" stroke-linecap="round" />
    <path d="M285 22 C268 22 257 34 257 49 C257 69 285 94 285 94 C285 94 313 69 313 49 C313 34 302 22 285 22 Z" fill="#d4500f" />
    <circle cx="285" cy="49" r="10" fill="#ffffff" />
  </svg>

  <!-- Appareil photo (pas encore de photo du produit) -->
  <svg v-else-if="name === 'camera'" viewBox="0 0 200 120" aria-hidden="true">
    <circle cx="100" cy="62" r="54" fill="#ffe2cc" />
    <rect x="52" y="40" width="96" height="66" rx="12" fill="#063b24" />
    <rect x="78" y="30" width="44" height="16" rx="5" fill="#063b24" />
    <circle cx="100" cy="73" r="22" fill="#ffffff" />
    <circle cx="100" cy="73" r="14" fill="#039a45" />
    <circle cx="95" cy="68" r="4" fill="#a6f3c2" />
    <circle cx="134" cy="52" r="4" fill="#f2b632" />
    <path d="M162 26 l3 7 l7 3 l-7 3 l-3 7 l-3 -7 l-7 -3 l7 -3 z" fill="#d4500f" />
    <path d="M36 88 l2 5 l5 2 l-5 2 l-2 5 l-2 -5 l-5 -2 l5 -2 z" fill="#039a45" />
  </svg>

  <!-- Conversation et reçu envoyés sur WhatsApp -->
  <svg v-else-if="name === 'whatsapp'" viewBox="0 0 350 150" aria-hidden="true">
    <circle cx="40" cy="30" r="30" fill="#d1fadf" />
    <rect x="44" y="18" width="110" height="150" rx="16" fill="#063b24" />
    <rect x="52" y="28" width="94" height="140" rx="10" fill="#ffffff" />
    <rect x="60" y="40" width="62" height="18" rx="9" fill="#e2e8f0" />
    <rect x="78" y="64" width="60" height="18" rx="9" fill="#a6f3c2" />
    <rect x="60" y="88" width="50" height="18" rx="9" fill="#e2e8f0" />
    <rect x="84" y="112" width="54" height="18" rx="9" fill="#a6f3c2" />
    <circle cx="176" cy="44" r="20" fill="#039a45" />
    <path d="M166 38 H186 V48 H175 L169 53 V48 H166 Z" fill="#ffffff" />
    <rect x="206" y="26" width="96" height="112" rx="4" fill="#ffffff" />
    <path d="M206 138 l8 8 l8 -8 l8 8 l8 -8 l8 8 l8 -8 l8 8 l8 -8 l8 8 l8 -8 l8 8 l8 -8" fill="#ffffff" />
    <rect x="222" y="40" width="64" height="8" rx="4" fill="#063b24" />
    <rect x="226" y="54" width="56" height="5" rx="2.5" fill="#cbd5e1" />
    <rect x="218" y="74" width="50" height="5" rx="2.5" fill="#cbd5e1" />
    <rect x="218" y="86" width="40" height="5" rx="2.5" fill="#cbd5e1" />
    <rect x="218" y="98" width="46" height="5" rx="2.5" fill="#cbd5e1" />
    <rect x="218" y="116" width="72" height="12" rx="6" fill="#039a45" />
  </svg>

  <!-- Coche et confettis : c'est prêt -->
  <svg v-else-if="name === 'done'" viewBox="0 0 350 150" aria-hidden="true">
    <circle cx="175" cy="75" r="58" fill="#d1fadf" />
    <rect x="60" y="30" width="12" height="6" rx="2" fill="#f2b632" transform="rotate(30 66 33)" />
    <rect x="96" y="96" width="12" height="6" rx="2" fill="#d4500f" transform="rotate(-20 102 99)" />
    <rect x="40" y="80" width="10" height="5" rx="2" fill="#039a45" transform="rotate(50 45 82)" />
    <rect x="250" y="34" width="12" height="6" rx="2" fill="#d4500f" transform="rotate(-35 256 37)" />
    <rect x="282" y="92" width="12" height="6" rx="2" fill="#f2b632" transform="rotate(25 288 95)" />
    <rect x="300" y="50" width="10" height="5" rx="2" fill="#039a45" transform="rotate(-60 305 52)" />
    <rect x="120" y="20" width="10" height="5" rx="2" fill="#039a45" transform="rotate(15 125 22)" />
    <rect x="226" y="118" width="10" height="5" rx="2" fill="#039a45" transform="rotate(40 231 120)" />
    <circle cx="82" cy="60" r="4" fill="#d4500f" />
    <circle cx="268" cy="70" r="4" fill="#039a45" />
    <circle cx="232" cy="22" r="3" fill="#f2b632" />
    <circle cx="120" cy="128" r="3" fill="#f2b632" />
    <circle cx="320" cy="120" r="4" fill="#d4500f" />
    <circle cx="30" cy="40" r="3" fill="#d4500f" />
    <circle cx="175" cy="75" r="38" fill="#039a45" />
    <path d="M158 76 l11 11 l22 -24" fill="none" stroke="#ffffff" stroke-width="7" stroke-linecap="round" stroke-linejoin="round" />
  </svg>
</template>
