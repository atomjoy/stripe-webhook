<?php

namespace App\Enums;

enum StockMovementType: string
{
    case ORDER = 'order';           // Zakup przez klienta (-)
    case RESTOCK = 'restock';       // Dostawa towaru (+)
    case RETURN = 'return';         // Zwrot od klienta (+)
    case REFUNDED = 'refunded';     // Zwrot od klienta (+)
    case ADJUSTMENT = 'adjustment'; // Ręczna korekta administratora (+/-)
    case DAMAGE = 'damage';         // Towar uszkodzony/spisany na straty (-)
    case EXPIRED = 'expired';       // Wygasła/anulowana płatność (+)
}
