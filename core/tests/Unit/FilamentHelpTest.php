<?php

namespace Tests\Unit;

use App\Support\FilamentHelp;
use Filament\Forms\Components\Repeater;
use PHPUnit\Framework\TestCase;

class FilamentHelpTest extends TestCase
{
    public function test_help_is_attached_to_the_label_without_leaking_into_repeater_actions(): void
    {
        $repeater = Repeater::make('nameservers')
            ->label(FilamentHelp::label(
                'Nameservers',
                'At least two authoritative nameservers are required for redundancy.',
            ))
            ->addActionLabel('Add nameserver');

        $this->assertStringContainsString('x-tooltip', (string) $repeater->getLabel());
        $this->assertSame('Add nameserver', $repeater->getAddActionLabel());
        $this->assertStringNotContainsString('<span', $repeater->getAddActionLabel());
    }
}
