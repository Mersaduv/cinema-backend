<?php

namespace Tests\Unit;

use App\GraphQL\Support\WatchAuthGate;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WatchAuthGateTest extends TestCase
{
    #[Test]
    public function enforce_throws_when_auth_required_and_guest(): void
    {
        Config::set('cinema.watch_auth_required', true);

        $this->expectException(Error::class);

        WatchAuthGate::enforce();
    }

    #[Test]
    public function enforce_is_noop_when_auth_not_required(): void
    {
        Config::set('cinema.watch_auth_required', false);

        WatchAuthGate::enforce();

        $this->assertTrue(true);
    }
}
