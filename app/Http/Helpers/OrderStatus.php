<?php


namespace App\Http\Helpers;


class OrderStatus
{
    const PENDING = 'pending';
    const COLECTOR_ASSIGNED = 'collector_assigned';
    const PROCESSING = 'processing';
    const DELIVERY_ASSIGNED = 'delivery_assigned';
    const DELIVERED = 'delivered';
    const CANCELLED = 'cancelled';
}
