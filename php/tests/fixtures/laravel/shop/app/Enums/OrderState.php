<?php

namespace App\Enums;

enum OrderState: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Shipped = 'shipped';
}
