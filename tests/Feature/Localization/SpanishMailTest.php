<?php

namespace Tests\Feature\Localization;

use App\Models\User;
use App\Support\LocalePreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class SpanishMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_password_reset_mail_uses_the_reader_locale(): void
    {
        $user = User::factory()->create();

        app(LocalePreference::class)->write($user, 'es');

        $this->post(route('password.request'), ['email' => $user->email])
            ->assertSessionHasNoErrors();

        $message = $this->lastMessage();

        $this->assertStringContainsString('Restablece tu contraseña', (string) $message->getSubject());
        $this->assertStringContainsString('Recibes este correo', (string) $message->getHtmlBody());
        $this->assertStringContainsString('Restablecer contraseña', (string) $message->getHtmlBody());
        $this->assertStringContainsString('Todos los derechos reservados.', (string) $message->getHtmlBody());
    }

    public function test_the_verification_mail_uses_the_reader_locale(): void
    {
        $user = User::factory()->unverified()->create();

        app(LocalePreference::class)->write($user, 'es');

        $user->sendEmailVerificationNotification();

        $message = $this->lastMessage();

        $this->assertStringContainsString('Verifica tu dirección de correo electrónico', (string) $message->getSubject());
        $this->assertStringContainsString('Haz clic en el botón de abajo', (string) $message->getHtmlBody());
        $this->assertStringContainsString('Verificar dirección de correo electrónico', (string) $message->getHtmlBody());
    }

    private function lastMessage(): Email
    {
        $transport = app('mailer')->getSymfonyTransport();

        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->assertCount(1, $transport->messages());

        $message = $transport->messages()->last()->getOriginalMessage();

        $this->assertInstanceOf(Email::class, $message);

        return $message;
    }
}
