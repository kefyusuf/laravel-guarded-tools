<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Team;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $companies = [
                ['Anadolu Tekstil', 'anadolu-tekstil', [['Yılmaz Konfeksiyon', 'İstanbul'], ['Demir Mağazacılık', 'Bursa'], ['Aydın Kumaş', 'Ankara'], ['Öztürk Giyim', 'Denizli'], ['Şahin Tekstil', 'Gaziantep']]],
                ['Ege Gıda', 'ege-gida', [['Kaya Market', 'İzmir'], ['Çelik Toptan', 'Manisa'], ['Aksoy Gıda', 'Aydın'], ['Deniz Restoran', 'Muğla'], ['Güneş Ticaret', 'Balıkesir']]],
            ];
            foreach ($companies as $index => [$name, $slug, $customerData]) {
                $team = Team::firstOrCreate(['slug' => $slug], ['name' => $name]);
                $customers = [];
                foreach ($customerData as [$customerName, $city]) {
                    $customers[] = Customer::firstOrCreate(['team_id' => $team->id, 'name' => $customerName], ['city' => $city]);
                }
                foreach (range(1, 45) as $i) {
                    $placed = now()->startOfDay()->subDays($i <= 3 ? 0 : (($i - 3) * 2))->setHour(10 + $i % 6);
                    $status = $i % 9 === 0 ? 'cancelled' : ['pending', 'shipped', 'paid'][$i % 3];
                    $amount = ($index + 1) * 140000 + $i * 13750;
                    $order = Order::firstOrCreate(['team_id' => $team->id, 'number' => sprintf('%s-2026-%04d', $index === 0 ? 'AT' : 'EG', $i)], [
                        'customer_id' => $customers[($i - 1) % count($customers)]->id,
                        'status' => $status, 'amount_cents' => $amount, 'placed_at' => $placed,
                    ]);
                    if ($status !== 'cancelled') {
                        Invoice::firstOrCreate(['team_id' => $team->id, 'number' => sprintf('%s-F-%04d', $index === 0 ? 'AT' : 'EG', $i)], [
                            'order_id' => $order->id, 'amount_cents' => $amount, 'due_at' => $placed->copy()->addDays(14),
                            'paid_at' => $status === 'paid' ? $placed->copy()->addDays(7) : null,
                        ]);
                    }
                }
                $users = $index === 0
                    ? [['Ayşe Yılmaz', 'owner@anadolu.test', 'owner'], ['Mehmet Demir', 'sales@anadolu.test', 'sales'], ['Elif Aydın', 'viewer@anadolu.test', 'viewer']]
                    : [['Can Kaya', 'owner@ege.test', 'owner']];
                foreach ($users as [$userName, $email, $role]) {
                    User::updateOrCreate(['email' => $email], ['name' => $userName, 'password' => 'demo-password',
                        'current_team_id' => $team->id, 'role' => $role, 'email_verified_at' => now()]);
                }
            }
        });
    }
}
