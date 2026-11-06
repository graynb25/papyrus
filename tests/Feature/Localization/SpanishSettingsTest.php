<?php

namespace Tests\Feature\Localization;

use App\Backup\BackupLibrary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;
use Tests\Support\MintsTokens;
use Tests\TestCase;

class SpanishSettingsTest extends TestCase
{
    use MintsTokens;
    use RefreshDatabase;

    private User $owner;

    private string $backupDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => User::ROLE_INSTALL_OWNER]);
        $this->owner->refresh();

        $this->backupDirectory = $this->scratchPath('papyrus-phase3-backups');
        $this->app->instance(BackupLibrary::class, new BackupLibrary($this->backupDirectory));
    }

    public function test_the_profile_page_speaks_spanish(): void
    {
        $this->actingAs($this->owner)
            ->withSession(['locale' => 'es'])
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee('Ajustes del perfil - Papyrus')
            ->assertSee('Tu sala, tus reglas.')
            ->assertSee('Cambia el nombre y la dirección de correo de esta cuenta')
            ->assertSee('Guardar')
            ->assertSee('Elimina esta cuenta, sus ajustes y su actividad de lectura');
    }

    public function test_the_appearance_page_speaks_spanish(): void
    {
        $this->actingAs($this->owner)
            ->withSession(['locale' => 'es'])
            ->get(route('appearance.edit'))
            ->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee('Ajustes de apariencia - Papyrus')
            ->assertSee('Apariencia')
            ->assertSee('Elige el aspecto de Papyrus')
            ->assertSee('Claro')
            ->assertSee('Oscuro')
            ->assertSee('Sistema')
            ->assertSee('Papel')
            ->assertSee('Tinta')
            ->assertSee('Idioma');
    }

    public function test_the_security_page_speaks_spanish(): void
    {
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);
        Features::passkeys([
            'confirmPassword' => true,
        ]);

        $this->actingAs($this->owner)
            ->withSession([
                'locale' => 'es',
                'auth.password_confirmed_at' => time(),
            ])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee('Ajustes de seguridad - Papyrus')
            ->assertSee('Actualizar contraseña')
            ->assertSee('Contraseña actual')
            ->assertSee('Nueva contraseña')
            ->assertSee('Autenticación en dos pasos')
            ->assertSee('Claves de acceso')
            ->assertSee('Todavía no hay claves de acceso')
            ->assertSee('Activar 2FA');
    }

    public function test_the_connections_page_speaks_spanish(): void
    {
        config(['papyrus.anilist.oauth_client_id' => null]);

        $this->actingAs($this->owner)
            ->withSession(['locale' => 'es'])
            ->get(route('connections.edit'))
            ->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee('Ajustes de conexión - Papyrus')
            ->assertSee('Sigue tu lectura en servicios externos')
            ->assertSee('Este servidor todavía no tiene registrada ninguna aplicación de AniList.')
            ->assertSee('Crea una en anilist.co/settings/developer')
            ->assertSee('Conectar AniList')
            ->assertSee('Cuando terminas un capítulo, su número se escribe en tu lista de AniList')
            ->assertSee('Conectar envía tu progreso de lectura a AniList');
    }

    public function test_the_about_page_speaks_spanish(): void
    {
        Http::fake(function (Request $request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            return Http::response(['data' => ['aboutServer' => ['version' => '2.0.0']]]);
        });

        $this->actingAs($this->owner)
            ->withSession(['locale' => 'es'])
            ->get(route('about.edit'))
            ->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee('Acerca de esta instalación - Papyrus')
            ->assertSee('Qué versiones están en ejecución')
            ->assertSee('Suwayomi')
            ->assertSee('Papyrus')
            ->assertSee('Las actualizaciones son manuales.')
            ->assertSee('Si informas de un problema');
    }

    public function test_the_licences_page_speaks_spanish(): void
    {
        $this->actingAs($this->owner)
            ->withSession(['locale' => 'es'])
            ->get(route('licences.edit'))
            ->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee('Licencias - Papyrus')
            ->assertSee('El software incluido en esta instalación')
            ->assertSee('Incluido en esta instalación')
            ->assertSee('PHP')
            ->assertSee('JavaScript')
            ->assertSee('Paquetes')
            ->assertSee('Leer la licencia');
    }

    public function test_the_backups_page_speaks_spanish(): void
    {
        $this->actingAs($this->owner)
            ->withSession(['locale' => 'es'])
            ->get(route('backups.edit'))
            ->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee('Copias de seguridad - Papyrus')
            ->assertSee('Guarda una copia de tu biblioteca, o vuelve a poner una')
            ->assertSee('Actividad más reciente')
            ->assertSee('Copias guardadas')
            ->assertSee('Hacer una copia ahora');
    }

    public function test_the_import_page_speaks_spanish(): void
    {
        $this->actingAs($this->owner)
            ->withSession(['locale' => 'es'])
            ->get(route('import.edit'))
            ->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee('Importar una copia de seguridad - Papyrus')
            ->assertSee('Trae tu biblioteca desde otra aplicación de lectura')
            ->assertSee('Creando la copia')
            ->assertSee('Archivo de copia de seguridad')
            ->assertSee('Tus importaciones')
            ->assertSee('Todavía no has importado nada.')
            ->assertSee('Inspeccionar')
            ->assertSee('Instalar las extensiones que necesita esta copia');
    }
}
