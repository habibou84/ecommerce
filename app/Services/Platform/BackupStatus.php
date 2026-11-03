<?php

namespace App\Services\Platform;

use Carbon\CarbonImmutable;

/**
 * État des sauvegardes écrit par deploy/backup.sh dans le dossier des sauvegardes (etat-sauvegarde.json,
 * etat-distant.json, etat-restauration.json) : sauvegarde de la nuit, copie hors du serveur, test de restauration
 * du dimanche. Chaque point est « ok », « warning » ou « error », avec ce qu'il faut faire.
 * Même lecture que Jibiat (habibou84/deliverynew), pour le même script.
 */
class BackupStatus
{
    /** Sauvegarde de la nuit : au-delà, elle ne tourne plus */
    public const MAX_AGE_HOURS = 26;

    /** Test de restauration chaque dimanche : au-delà, il ne tourne plus */
    public const RESTORE_MAX_AGE_DAYS = 8;

    /** @return array{0: string, 1: string} */
    public function nightly(): array
    {
        $state = $this->state('sauvegarde');
        if ($state === null) {
            return $this->legacyFiles();
        }
        if (! ($state['ok'] ?? false)) {
            return ['error', ($state['message'] ?? 'La dernière sauvegarde a échoué.').' Le '.$this->when($state).'.'];
        }
        $hours = $this->ageHours($state);
        if ($hours > self::MAX_AGE_HOURS) {
            return ['error', 'Dernière sauvegarde il y a '.$hours.' h : la sauvegarde de la nuit ne tourne plus (journal : /var/log/<installation>-backup.log).'];
        }

        return ['ok', 'Dernière sauvegarde il y a '.$hours.' h : base '.$this->size($state['base_bytes'] ?? 0)
            .', fichiers '.$this->size($state['files_bytes'] ?? 0).', '.($state['copies'] ?? 1).' copie(s) de la base gardée(s).'];
    }

    /** @return array{0: string, 1: string} */
    public function offsite(): array
    {
        $state = $this->state('distant');
        if ($state === null) {
            return app()->isProduction()
                ? ['warning', 'Copie hors du serveur non configurée : si le serveur disparaît, les sauvegardes disparaissent avec lui (docs/SAUVEGARDES.md).']
                : ['ok', 'Pas utilisée hors production.'];
        }
        if (! ($state['ok'] ?? false)) {
            return ['error', ($state['message'] ?? 'Envoi impossible.').' Le '.$this->when($state).'.'];
        }
        $hours = $this->ageHours($state);
        if ($hours > self::MAX_AGE_HOURS) {
            return ['error', 'Dernière copie envoyée il y a '.$hours.' h : la copie hors du serveur ne part plus.'];
        }

        return ['ok', 'Copie chiffrée envoyée il y a '.$hours.' h ('.($state['files'] ?? 0).' fichier(s), '.$this->size($state['bytes'] ?? 0).').'];
    }

    /** @return array{0: string, 1: string} */
    public function restore(): array
    {
        $state = $this->state('restauration');
        if ($state === null) {
            return $this->state('sauvegarde') === null
                ? ['warning', 'Aucun test de restauration : les sauvegardes automatiques ne sont pas encore installées.']
                : ['warning', 'Pas encore de test de restauration : il a lieu chaque dimanche à 6 h 10.'];
        }
        if (! ($state['ok'] ?? false)) {
            return ['error', 'La sauvegarde '.($state['backup'] ?? '').' ne se restaure pas : '.($state['message'] ?? 'erreur').'. Le '.$this->when($state).'.'];
        }
        $days = (int) floor($this->ageHours($state) / 24);
        if ($days > self::RESTORE_MAX_AGE_DAYS) {
            return ['warning', 'Dernier test de restauration il y a '.$days.' jours : le test du dimanche ne tourne plus.'];
        }
        $counts = $state['counts'] ?? [];
        $orders = $counts['orders'][0] ?? null;
        $stores = $counts['stores'][0] ?? null;

        return ['ok', 'Sauvegarde restaurée avec succès le '.$this->when($state)
            .($orders !== null ? ' : '.$orders.' commande(s), '.(int) $stores.' boutique(s) relues.' : '.')];
    }

    /**
     * Ancienne sauvegarde faite à la main (avant deploy/backup.sh) : seul l'âge du dernier fichier est connu.
     *
     * @return array{0: string, 1: string}
     */
    private function legacyFiles(): array
    {
        $dir = $this->dir();
        $files = $dir !== '' && is_dir($dir) ? (glob($dir.'/*') ?: []) : [];
        $files = array_filter($files, 'is_file');
        if ($files === []) {
            return ['error', 'Aucune sauvegarde trouvée dans '.($dir ?: '(dossier non réglé)').' : elles s\'installent à la prochaine mise à jour du serveur (docs/SAUVEGARDES.md).'];
        }
        $hours = (int) floor((time() - max(array_map('filemtime', $files))) / 3600);

        return $hours <= self::MAX_AGE_HOURS
            ? ['ok', 'Dernière sauvegarde il y a '.$hours.' h.']
            : ['error', 'Dernière sauvegarde il y a '.$hours.' h : la sauvegarde de la nuit ne tourne plus.'];
    }

    /** @return array<string, mixed>|null */
    private function state(string $name): ?array
    {
        $file = $this->dir().'/etat-'.$name.'.json';
        if ($this->dir() === '' || ! is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : ['ok' => false, 'message' => 'État illisible ('.basename($file).').'];
    }

    private function dir(): string
    {
        return rtrim((string) config('platform.backup_path'), '/');
    }

    /** @param array<string, mixed> $state */
    private function ageHours(array $state): int
    {
        $at = isset($state['at']) ? CarbonImmutable::parse($state['at']) : null;

        return $at ? (int) floor($at->diffInMinutes(now(), true) / 60) : PHP_INT_MAX;
    }

    /** @param array<string, mixed> $state */
    private function when(array $state): string
    {
        return isset($state['at'])
            ? CarbonImmutable::parse($state['at'])->setTimezone(config('app.timezone'))->locale('fr')->isoFormat('D MMMM à HH:mm')
            : '?';
    }

    private function size(int|float $bytes): string
    {
        return match (true) {
            $bytes >= 1024 ** 3 => round($bytes / 1024 ** 3, 1).' Go',
            $bytes >= 1024 ** 2 => round($bytes / 1024 ** 2, 1).' Mo',
            default => max(1, (int) round($bytes / 1024)).' Ko',
        };
    }
}
