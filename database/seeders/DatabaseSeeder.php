<?php

namespace Database\Seeders;

use App\Models\Manager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Agent;
use App\Models\Customer;
use App\Models\Zone;
use App\Models\Address;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Collect;
use App\Models\CollectItem;
use App\Models\Delivery;
use App\Models\DeliveryItem;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ServiceSeeder::class,
            CountriesSeeder::class,
            OperatorsSeeder::class,
        ]);

    }
}
