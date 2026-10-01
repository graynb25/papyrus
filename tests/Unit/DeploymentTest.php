<?php

namespace Tests\Unit;

use App\Support\Deployment;
use InvalidArgumentException;
use Tests\TestCase;

class DeploymentTest extends TestCase
{
    private function withMode(string $mode): Deployment
    {
        config(['papyrus.deployment_mode' => $mode]);

        return new Deployment;
    }

    public function test_single_mode_disables_registration(): void
    {
        $deployment = $this->withMode(Deployment::SINGLE);

        $this->assertTrue($deployment->isSingle());
        $this->assertFalse($deployment->allowsRegistration());
        $this->assertFalse($deployment->enforcesQuotas());
    }

    public function test_shared_and_public_modes_allow_registration(): void
    {
        $this->assertTrue($this->withMode(Deployment::SHARED)->allowsRegistration());
        $this->assertTrue($this->withMode(Deployment::PUBLIC)->allowsRegistration());
        $this->assertTrue($this->withMode(Deployment::PUBLIC)->enforcesQuotas());
    }

    public function test_it_rejects_an_unknown_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->withMode('enterprise')->mode();
    }
}
