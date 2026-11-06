<?php

namespace Tests\Feature\Localization;

use App\Models\User;
use App\Support\Deployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class SpanishAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_speaks_spanish(): void
    {
        $this->withSession(['locale' => 'es'])
            ->get(route('login'))
            ->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee('Iniciar sesión')
            ->assertSee('Correo electrónico')
            ->assertSee('Contraseña')
            ->assertSee('¿Olvidaste tu contraseña?')
            ->assertSee('Iniciar sesión - Papyrus');
    }

    public function test_the_registration_page_speaks_spanish(): void
    {
        config(['papyrus.deployment_mode' => Deployment::SHARED]);

        $this->withSession(['locale' => 'es'])
            ->get(route('register'))
            ->assertOk()
            ->assertSee('Crea una cuenta')
            ->assertSee('Nombre completo')
            ->assertSee('Crear cuenta')
            ->assertSee('¿Ya tienes una cuenta?')
            ->assertSee('Crear cuenta - Papyrus');
    }

    public function test_the_forgot_password_page_speaks_spanish(): void
    {
        $this->withSession(['locale' => 'es'])
            ->get(route('password.request'))
            ->assertOk()
            ->assertSee('Olvidé mi contraseña')
            ->assertSee('Enviar enlace para restablecer la contraseña')
            ->assertSee('O vuelve a')
            ->assertSee('Olvidé mi contraseña - Papyrus');
    }

    public function test_the_reset_password_page_speaks_spanish(): void
    {
        $this->withSession(['locale' => 'es'])
            ->get(route('password.reset', ['token' => 'test-token', 'email' => 'reader@example.test']))
            ->assertOk()
            ->assertSee('Restablecer contraseña')
            ->assertSee('Introduce tu nueva contraseña')
            ->assertSee('Confirmar contraseña')
            ->assertSee('Restablecer contraseña - Papyrus');
    }

    public function test_the_verification_page_speaks_spanish(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->withSession(['locale' => 'es'])
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('Verificación de correo electrónico')
            ->assertSee('Verifica tu dirección de correo electrónico')
            ->assertSee('Reenviar correo de verificación')
            ->assertSee('Verificación de correo electrónico - Papyrus');
    }

    public function test_the_confirm_password_page_speaks_spanish(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['locale' => 'es'])
            ->get(route('password.confirm'))
            ->assertOk()
            ->assertSee('Confirmar contraseña')
            ->assertSee('Por tu seguridad, Papyrus necesita tu contraseña de nuevo antes de este cambio.')
            ->assertSee('Confirmar')
            ->assertSee('Confirmar contraseña - Papyrus');
    }

    public function test_validation_errors_are_spanish_and_use_localized_attributes(): void
    {
        config(['papyrus.deployment_mode' => Deployment::SHARED]);

        $this->withSession(['locale' => 'es'])
            ->from(route('register'))
            ->post(route('register.store'), [])
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors(['name' => 'El campo nombre es obligatorio.']);
    }

    public function test_authentication_failure_is_spanish(): void
    {
        $user = User::factory()->create();

        $this->withSession(['locale' => 'es'])
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors([
                'email' => 'Estas credenciales no coinciden con nuestros registros.',
            ]);
    }

    public function test_the_password_reset_request_status_is_spanish(): void
    {
        $user = User::factory()->create();

        $this->withSession(['locale' => 'es'])
            ->post(route('password.request'), ['email' => $user->email])
            ->assertSessionHas(
                'status',
                'Te hemos enviado por correo el enlace para restablecer la contraseña.',
            );
    }

    public function test_the_two_factor_challenge_speaks_spanish(): void
    {
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $this->withSession(['locale' => 'es'])
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('two-factor.login'));

        $this->get(route('two-factor.login'))
            ->assertOk()
            ->assertSee('Autenticación en dos pasos')
            ->assertSee('Código de autenticación')
            ->assertSee('Código de un solo uso')
            ->assertSee('Continuar')
            ->assertSee('Autenticación en dos pasos - Papyrus');
    }
}
