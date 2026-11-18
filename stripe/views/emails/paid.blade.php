<x-mail::message>
# Dziękujemy za zakup, {{ $order->customer_name }}!

Twoja płatność za zamówienie **{{ $order->order_number }}** została zaksięgowana. Przystępujemy do jego realizacji.

## Podsumowanie zamówienia

<x-mail::table>

| Produkt | Wariant / SKU | Ilość | Kwota |
| :--- | :--- | :---: | ---: |
@foreach($order->items as $item)

| {{ $item->product_name }} | {{ $item->sku }} @if(!empty($item->attributes)) ({{ collect($item->attributes)->pluck('label')->implode(', ') }}) @endif | {{ $item->quantity }} | {{ number_format($item->total / 100, 2, ',', ' ') }} zł |
@endforeach
</x-mail::table>

* **Wartość produktów:** {{ number_format($order->subtotal / 100, 2, ',', ' ') }} zł
@if($order->discount_amount > 0)
* **Rabat @if($order->coupon_code)({{ $order->coupon_code }})@endif:** -{{ number_format($order->discount_amount / 100, 2, ',', ' ') }} zł
@endif
@if($order->pack_amount > 0)
* **Opakowania:** {{ number_format($order->pack_amount / 100, 2, ',', ' ') }} zł
@endif
* **Dostawa:** {{ $order->shipping_amount === 0 ? '0,00 zł (Darmowa)' : number_format($order->shipping_amount / 100, 2, ',', ' ') . ' zł' }}

**Łącznie zapłacono:** {{ number_format($order->total / 100, 2, ',', ' ') }} zł

## Dane dostawy
* **Odbiorca:** {{ $order->customer_name }}
@if($order->shipping_locker_code)
* **Paczkomat InPost:** {{ $order->shipping_locker_code }} @if($order->shipping_locker_details)({{ $order->shipping_locker_details }})@endif
@else
* **Adres:** {{ $order->shipping_address }}, {{ $order->shipping_zip }} {{ $order->shipping_city }}
@endif
@if($order->customer_phone)
* **Telefon:** {{ $order->customer_phone }}
@endif
@if($order->wants_invoice && $order->company_nip)
* **Faktura VAT:** {{ $order->company_name }} (NIP: {{ $order->company_nip }})
@endif

<x-mail::button :url="\Illuminate\Support\Facades\URL::signedRoute('checkout.success', ['order' => $order->uuid])">
Zobacz szczegóły zamówienia
</x-mail::button>

Pozdrawiamy,<br>
Zespół {{ config('app.name') }}
</x-mail::message>
