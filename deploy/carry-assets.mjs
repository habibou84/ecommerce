// Mise à jour sans coupure : recopie dans le nouveau dossier de l'interface les fichiers de la
// version en ligne (ceux de son manifest.json), et ceux des versions de la dernière semaine. Les pages
// déjà ouvertes, même depuis plusieurs mises à jour, chargent encore leurs morceaux d'interface ; une
// page plus ancienne se recharge toute seule au premier clic (router.onError). Les dates des fichiers
// sont gardées, pour que les versions de plus d'une semaine finissent par disparaître.
//
//   node deploy/carry-assets.mjs public/build public/build-next

import { copyFileSync, existsSync, mkdirSync, readdirSync, readFileSync, statSync, utimesSync } from 'node:fs'
import { dirname, join } from 'node:path'

const KEEP_DAYS = 7
const [from, to] = process.argv.slice(2)
const manifest = join(from, 'manifest.json')
if (!existsSync(manifest)) process.exit(0)

const files = new Set()
for (const entry of Object.values(JSON.parse(readFileSync(manifest, 'utf8')))) {
  for (const file of [entry.file, ...(entry.css || []), ...(entry.assets || [])]) {
    if (file) files.add(file)
  }
}

// Versions précédentes encore récentes
const assets = join(from, 'assets')
const since = Date.now() - KEEP_DAYS * 86400000
if (existsSync(assets)) {
  for (const name of readdirSync(assets)) {
    if (statSync(join(assets, name)).mtimeMs >= since) files.add(`assets/${name}`)
  }
}

let copied = 0
for (const file of files) {
  const source = join(from, file)
  if (!existsSync(source)) continue
  const target = join(to, file)
  mkdirSync(dirname(target), { recursive: true })
  copyFileSync(source, target)
  const { atime, mtime } = statSync(source)
  utimesSync(target, atime, mtime)
  copied++
}
console.log(`    ${copied} fichier(s) des versions en ligne gardé(s)`)
