// Application Jibiat Marchand : la gestion en ligne s'ouvre dans l'application (connexion sur la plateforme, puis
// la boutique choisie sur son sous-domaine). JM_DOMAIN choisit le serveur : préproduction pour les essais.
const domain = process.env.JM_DOMAIN || 'jibiat-marchand.com'
// Notifications push : seulement si la construction a le fichier Firebase (google-services.json)
const push = require('fs').existsSync(`${__dirname}/android/app/google-services.json`)

module.exports = {
  appId: 'com.jibiat.marchand',
  appName: 'Jibiat Marchand',
  webDir: 'www',
  server: {
    url: `https://${domain}/connexion?app=1`,
    // Pages ouvertes dans l'application ; les autres liens (WhatsApp, téléphone, cartes) partent vers leur application
    allowNavigation: [domain, `*.${domain}`],
    errorPath: 'hors-ligne.html',
  },
  appendUserAgent: push ? 'JibiatMarchandApp JibiatMarchandPush' : 'JibiatMarchandApp',
  backgroundColor: '#ffffff',
  android: {
    allowMixedContent: false,
  },
  plugins: {
    SplashScreen: { launchShowDuration: 1200, backgroundColor: '#047857', showSpinner: false },
    // Icônes sombres sur le fond blanc de la page
    StatusBar: { style: 'LIGHT' },
    PushNotifications: { presentationOptions: ['badge', 'sound', 'alert'] },
  },
}
