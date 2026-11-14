<?php

namespace Tests\Feature;

use App\Filament\Domain\Resources\Domains\Pages\ViewDomain;
use Livewire\Attributes\On;
use ReflectionClass;
use Tests\TestCase;

class DomainPageEventsTest extends TestCase
{
    /** A tab action dispatches domain-updated; the page must have a public handler for it or Livewire answers 500. */
    public function test_the_domain_page_handles_the_domain_updated_event_with_a_public_method(): void
    {
        $handlers = [];
        foreach ((new ReflectionClass(ViewDomain::class))->getMethods() as $method) {
            foreach ($method->getAttributes(On::class) as $attribute) {
                if ($attribute->newInstance()->event === 'domain-updated') {
                    $handlers[] = $method;
                }
            }
        }
        $this->assertCount(1, $handlers);
        $this->assertTrue($handlers[0]->isPublic());
        $this->assertFalse($handlers[0]->isStatic());
    }
}
