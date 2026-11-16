<?php

namespace App\Tests\Profile;

use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Profile;
use App\Dashboard\Model\Section;
use App\Profile\CredentialKinds;
use App\Profile\Kind\Gw2CredentialKind;
use App\Source\Gw2\Gw2Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class CredentialKindsTest extends TestCase
{
    private function kinds(): CredentialKinds
    {
        return new CredentialKinds([new Gw2CredentialKind(new Gw2Client(new MockHttpClient()))]);
    }

    private function profile(string $type, ?string $credentials): Profile
    {
        $card = new CardInstance('c', $type, null, 1, 300, ['credentials' => $credentials]);

        return new Profile('zoe', 'Zoé', 'teal', [new Section('Jeux', 4, [$card])]);
    }

    public function testAnyCardTypeOfTheKindShowsItsSection(): void
    {
        foreach (['gw2', 'gw2-bosses'] as $type) {
            self::assertArrayHasKey('gw2', $this->kinds()->usedBy($this->profile($type, 'profile')), $type);
        }
    }

    public function testNotShownWithoutCredentialsProfile(): void
    {
        self::assertSame([], $this->kinds()->usedBy($this->profile('gw2-bosses', null)));
    }
}
