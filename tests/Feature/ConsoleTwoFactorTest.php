<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\User;
use App\Services\Accounts\ConsoleTwoFactor;
use App\Services\Accounts\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ConsoleTwoFactorTest extends TestCase
{
    private const CONSOLE = 'http://admin.localhost';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['phone' => '+2250701020304', 'password' => 'motdepasse', 'name' => 'Équipe Jibiat']);
        $this->admin->forceFill(['is_super_admin' => true])->save();
    }

    private function console(string $method, string $path, array $data = []): TestResponse
    {
        return $this->apiOn(self::CONSOLE, $method, 'console/'.$path, $data);
    }

    private function login(): string
    {
        return $this->console('POST', 'auth/login', ['phone' => '0701020304', 'password' => 'motdepasse'])->assertStatus(202)
            ->assertJsonPath('method', 'totp')->json('token');
    }

    public function test_codes_follow_rfc_6238(): void
    {
        $secret = Totp::base32('12345678901234567890');
        $this->assertSame('287082', Totp::code($secret, Totp::step(59)));
        $this->assertSame('005924', Totp::code($secret, Totp::step(1234567890)));
        // Un pas d'écart toléré (horloge du téléphone), pas deux
        $this->assertSame(Totp::step(1234567890) - 1, Totp::match($secret, Totp::code($secret, Totp::step(1234567860)), 1234567890));
        $this->assertNull(Totp::match($secret, Totp::code($secret, Totp::step(1234567830)), 1234567890));
        $this->assertStringStartsWith('otpauth://totp/Jibiat%20Marchand%20Console%3A%2B2250701020304?secret=', Totp::uri(Totp::secret(), ConsoleTwoFactor::ISSUER, '+2250701020304'));
    }

    public function test_first_access_then_login_with_the_app_code_or_a_recovery_code(): void
    {
        // Premier accès : code par SMS (aucune adresse), puis activation obligatoire
        $start = $this->console('POST', 'auth/login', ['phone' => '0701020304', 'password' => 'motdepasse'])->assertStatus(202);
        $this->console('POST', 'auth/verify', ['token' => $start->json('verification.token'), 'code' => $start->json('demo_code')])->assertOk();
        $this->console('GET', 'journal')->assertForbidden();
        $draft = $this->console('POST', '2fa/setup')->assertOk()->assertJsonPath('data.uri', fn ($uri) => str_starts_with($uri, 'otpauth://totp/'))
            ->assertJsonPath('data.qr', fn ($qr) => str_starts_with($qr, 'data:image/png;base64,'))->json('data');
        $this->console('POST', '2fa/confirm', ['code' => '000000'])->assertStatus(422);
        $codes = $this->console('POST', '2fa/confirm', ['code' => Totp::code($draft['secret'], Totp::step())])->assertOk()->json('data.recovery_codes');
        $this->assertCount(8, $codes);
        $this->console('GET', '2fa')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.recovery_left', 8);
        $this->console('GET', 'journal')->assertOk();

        // Secret chiffré en base, jamais renvoyé
        $raw = DB::table('users')->where('id', $this->admin->id)->value('two_factor_secret');
        $this->assertNotSame($draft['secret'], $raw);
        $this->assertSame($draft['secret'], $this->admin->fresh()->two_factor_secret);
        $this->assertStringNotContainsString($draft['secret'], $this->console('GET', 'auth/me')->getContent());

        // Connexion suivante : mot de passe puis code de l'application
        $this->console('POST', 'auth/logout');
        $this->travel(1)->minutes();
        $token = $this->login();
        $this->console('GET', 'stats')->assertUnauthorized();
        $this->console('POST', 'auth/totp', ['token' => $token, 'code' => '000000'])->assertStatus(422);
        $code = Totp::code($draft['secret'], Totp::step());
        $this->console('POST', 'auth/totp', ['token' => $token, 'code' => $code])->assertOk()->assertJsonPath('data.two_factor', true);
        $this->console('GET', 'stats')->assertOk();

        // Le même code ne sert pas deux fois
        $this->console('POST', 'auth/logout');
        $this->console('POST', 'auth/totp', ['token' => $this->login(), 'code' => $code])->assertStatus(422);

        // Code de secours : une seule fois
        $this->travel(2)->minutes();
        $this->console('POST', 'auth/totp', ['token' => $this->login(), 'code' => strtoupper($codes[0])])->assertOk();
        $this->console('GET', '2fa')->assertJsonPath('data.recovery_left', 7);
        $this->console('POST', 'auth/logout');
        $this->console('POST', 'auth/totp', ['token' => $this->login(), 'code' => $codes[0]])->assertStatus(422);

        // 5 essais au plus : la demande est annulée, même avec le bon code ensuite
        $this->travel(2)->minutes();
        $token = $this->login();
        foreach (range(1, 5) as $i) {
            $this->console('POST', 'auth/totp', ['token' => $token, 'code' => '000000'])->assertStatus(422);
        }
        $this->travel(1)->minutes();
        $this->console('POST', 'auth/totp', ['token' => $token, 'code' => Totp::code($draft['secret'], Totp::step())])->assertStatus(422);
        $this->assertEqualsCanonicalizing(['code', 'app', 'recovery'], AdminAction::where('action', 'console_login')->get()->pluck('data.with')->unique()->values()->all());
    }

    public function test_codes_by_email_sms_or_whatsapp_no_longer_open_the_console(): void
    {
        // Demande commencée avant l'activation : refusée ensuite
        $start = $this->console('POST', 'auth/login', ['phone' => '0701020304', 'password' => 'motdepasse', 'channel' => 'phone'])->assertStatus(202);
        $this->admin->forceFill(self::consoleTwoFactor())->save();
        $this->console('POST', 'auth/verify', ['token' => $start->json('verification.token'), 'code' => $start->json('demo_code')])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->console('GET', 'auth/me')->assertUnauthorized();
        // Et la connexion ne propose plus que l'application
        $this->console('POST', 'auth/login', ['phone' => '0701020304', 'password' => 'motdepasse', 'channel' => 'phone'])->assertStatus(202)->assertJsonPath('method', 'totp');
    }

    public function test_new_phone_recovery_codes_and_reset_by_the_team(): void
    {
        $this->signInConsole();
        $secret = $this->admin->fresh()->two_factor_secret;

        // Changer de téléphone ou refaire les codes de secours : mot de passe actuel
        $this->console('POST', '2fa/setup')->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->console('POST', '2fa/recovery-codes', ['current_password' => 'mauvais'])->assertStatus(422);
        $this->console('POST', '2fa/recovery-codes', ['current_password' => 'motdepasse'])->assertOk()->assertJsonCount(8, 'data.recovery_codes');
        $draft = $this->console('POST', '2fa/setup', ['current_password' => 'motdepasse'])->assertOk()->json('data.secret');
        // Tant que le nouveau n'est pas confirmé, l'ancien reste valable
        $this->assertSame($secret, $this->admin->fresh()->two_factor_secret);
        $this->travel(1)->minutes();
        $this->console('POST', '2fa/confirm', ['code' => Totp::code($draft, Totp::step())])->assertOk();
        $this->assertSame($draft, $this->admin->fresh()->two_factor_secret);

        // Un collègue a perdu son téléphone : réinitialisé par l'équipe, avec le mot de passe
        $other = User::factory()->create(['phone' => '+2250505050505', 'password' => 'motdepasse', 'name' => 'Koffi']);
        $other->forceFill(['is_super_admin' => true] + self::consoleTwoFactor())->save();
        $this->console('GET', 'team')->assertOk()->assertJsonPath('data.0.two_factor', true);
        $this->console('POST', "team/{$this->admin->id}/reset-2fa", ['current_password' => 'motdepasse'])->assertStatus(422)->assertJsonValidationErrors('admin');
        $this->console('POST', "team/{$other->id}/reset-2fa", ['current_password' => 'mauvais'])->assertStatus(422);
        $this->console('POST', "team/{$other->id}/reset-2fa", ['current_password' => 'motdepasse'])->assertOk();
        $this->assertFalse(ConsoleTwoFactor::enabled($other->fresh()));
        $this->assertTrue(AdminAction::where('action', 'console_2fa_reset')->exists());
        // Sa prochaine connexion repasse par un code, puis une nouvelle application
        $this->console('POST', 'auth/logout');
        $this->console('POST', 'auth/login', ['phone' => '0505050505', 'password' => 'motdepasse'])->assertStatus(202)->assertJsonMissingPath('method');

        // Dernier recours sur le serveur
        $this->artisan('platform:2fa-reset', ['phone' => '0701020304'])->assertSuccessful();
        $this->assertFalse(ConsoleTwoFactor::enabled($this->admin->fresh()));
    }
}
