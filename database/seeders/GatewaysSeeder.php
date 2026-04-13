<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class GatewaysSeeder extends Seeder
{
    public function run()
    {
        $now = Carbon::now();


        $gateways = [
            // Congo
            [
                'name' => 'MTN Congo',
                'code' => 'MTN',
                'method' => 'all',
                'logo' => 'https://example.com/logos/mtn.png',
                'is_active' => 1,
                'type' => 'mobile_money',
                'created_at' => $now,
                'updated_at' => $now
            ],
            [
                'name' => 'MTN Congo',
                'code' => 'MTN',
                'method' => 'all',
                'logo' => 'https://example.com/logos/mtn.png',
                'is_active' => 1,
                'type' => 'mobile_money',
                'created_at' => $now,
                'updated_at' => $now
            ],
        ];

        DB::table('gateways')->insert($operators);
    }
}
