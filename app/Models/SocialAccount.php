<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/**
 * Compte de réseau social connecté par la boutique : page Facebook, compte Instagram professionnel, compte TikTok.
 */
class SocialAccount extends Model
{
    use BelongsToStore;

    public const NETWORKS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok'];

    protected $fillable = ['store_id', 'network', 'external_id', 'name', 'access_token', 'refresh_token', 'token_expires_at', 'page_id', 'status', 'last_error', 'connected_by'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'token_expires_at' => 'datetime'];
    }
}
