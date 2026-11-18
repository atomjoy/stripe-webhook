<?php

namespace App\Enums;

enum PaymentMethod: string
{
	case BLIK = 'blik';
	case STRIPE = 'stripe';               // Przelewy24 / PayU / Stripe
	case CASH_ON_DELIVERY = 'cod';        // Za pobraniem u kuriera
	case BANK_TRANSFER = 'bank_transfer'; // Przelew tradycyjny
	case PAY_ON_PICKUP = 'pay_on_pickup'; // Płatność przy odbiorze (kierowca / punkt)

	public function label(): string
	{
		return match ($this) {
			self::BLIK => 'Płatność blik',
			self::STRIPE => 'Platność Stripe',
			self::BANK_TRANSFER => 'Przelew tradycyjny',
			self::CASH_ON_DELIVERY => 'Płatność przy odbiorze (pobranie)',
			self::PAY_ON_PICKUP => 'Płatność gotówką / kartą przy odbiorze',
		};
	}

	public function isOffline(): bool
	{
		return match ($this) {
			self::CASH_ON_DELIVERY, self::PAY_ON_PICKUP, self::BANK_TRANSFER => true,
			default => false,
		};
	}
}
