<?php

namespace Database\Factories;

use App\Enums\PrinterConnection;
use App\Models\Printer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Printer>
 */
class PrinterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Counter printer',
            'channel' => PrinterConnection::Browser,
            'target' => null,
            'paper' => '80',
            'cuts' => true,
            'has_drawer' => false,
            'drawer_pin' => 0,
            'feed_lines' => 4,
            'is_active' => true,
        ];
    }

    public function windowsShare(string $target = '\\localhost\THERMAL'): static
    {
        return $this->state([
            'channel' => PrinterConnection::WindowsShare,
            'target' => $target,
        ]);
    }

    public function network(string $target = '192.168.1.50:9100'): static
    {
        return $this->state([
            'channel' => PrinterConnection::Network,
            'target' => $target,
        ]);
    }

    public function withDrawer(int $pin = 0): static
    {
        return $this->state(['has_drawer' => true, 'drawer_pin' => $pin]);
    }

    public function narrow(): static
    {
        return $this->state(['paper' => '58']);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
