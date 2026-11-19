<?php

declare(strict_types=1);

namespace CoinsnapModified;

// Order and customer data sent with an invoice, so the merchant can find the order from Coinsnap / BTCPay.
// Plain values only: the API stores them as JSON and escapes them where it shows them.
final class InvoiceMetadata
{
    public const PLATFORM = 'modified';

    /**
     * @param array  $order       The checkout's order object cast to an array (info, customer, billing, products).
     * @param string $orderId     The order number (orders_id), the one shown in the admin order list.
     * @param array  $totals      Order total rows (code or class, value): $order_totals of checkout_process.php or the orders_total table.
     * @param string $orderUrl    Admin URL of the order.
     */
    public static function build(array $order, string $orderId, array $totals = [], string $orderUrl = '', string $pluginVersion = ''): array
    {
        $info = self::arr($order['info'] ?? null);
        $customer = self::arr($order['customer'] ?? null);
        $billing = self::arr($order['billing'] ?? null);
        if (self::str($billing['lastname'] ?? null) === '' && self::str($billing['street_address'] ?? null) === '') {
            $billing = $customer;
        }

        $name = self::join([$billing['firstname'] ?? $customer['firstname'] ?? null, $billing['lastname'] ?? $customer['lastname'] ?? null]);
        $country = self::arr($billing['country'] ?? null);

        $meta = [
            'orderId' => $orderId,
            'orderNumber' => $orderId,
            'orderUrl' => $orderUrl,
            'platform' => self::PLATFORM,
            'pluginVersion' => $pluginVersion,
            // Personal data: the module has no "send customer data" setting, so it is always sent.
            'customerName' => $name,
            'buyerName' => $name,
            'buyerEmail' => $customer['email_address'] ?? null,
            'buyerPhone' => $customer['telephone'] ?? null,
            'buyerCompany' => $billing['company'] ?? null,
            'buyerAddress1' => self::join([$billing['street_address'] ?? null, $billing['house_number'] ?? null]),
            'buyerAddress2' => self::join([$billing['additional_address_info'] ?? null, $billing['suburb'] ?? null], ', '),
            'buyerCity' => $billing['city'] ?? null,
            'buyerZip' => $billing['postcode'] ?? null,
            'buyerState' => $billing['state'] ?? null,
            'buyerCountry' => $country['iso_code_2'] ?? $country['title'] ?? (is_string($billing['country'] ?? null) ? $billing['country'] : null),
            'buyerMessage' => $info['comments'] ?? null,
            'shippingMethod' => $info['shipping_method'] ?? null,
            'paymentMethod' => $info['payment_method'] ?? null,
            'currency' => $info['currency'] ?? null,
            'orderTotal' => self::num($info['total'] ?? null),
            'language' => $info['language'] ?? null,
        ];

        $lineItems = [];
        $names = [];
        $codes = [];
        foreach (self::arr($order['products'] ?? null) as $product) {
            if (!is_array($product)) {
                continue;
            }
            $qty = self::num($product['qty'] ?? null);
            $price = self::num($product['price'] ?? null);
            $item = self::clean([
                'name' => $product['name'] ?? null,
                'sku' => $product['model'] ?? null,
                'productId' => $product['id'] ?? null,
                'quantity' => $qty,
                'price' => $price,
                'total' => ($qty !== null && $price !== null) ? round($qty * $price, 2) : null,
            ]);
            if ($item === []) {
                continue;
            }
            $lineItems[] = $item;
            if (isset($item['name'])) {
                $names[] = ($qty !== null && $qty != 1 ? self::qty($qty) . ' x ' : '') . $item['name'];
            }
            $code = $item['sku'] ?? $item['productId'] ?? null;
            if ($code !== null) {
                $codes[] = (string) $code;
            }
        }
        $meta['lineItems'] = $lineItems;
        $meta['itemDesc'] = $names !== [] ? self::cut(implode(', ', $names), 250) : null;
        $meta['itemCode'] = $codes !== [] ? self::cut(implode(', ', $codes), 250) : null;

        // Order totals rows: ot_subtotal, ot_shipping, ot_tax, ot_discount, ot_coupon, ot_gv, ... ("class" when read from the database)
        $tax = null;
        $discount = null;
        foreach ($totals as $row) {
            $class = is_array($row) ? ($row['code'] ?? $row['class'] ?? null) : null;
            if ($class === null) {
                continue;
            }
            $value = self::num($row['value'] ?? null);
            if ($value === null) {
                continue;
            }
            switch ($class) {
                case 'ot_subtotal':
                    $meta['subtotal'] = $value;
                    break;
                case 'ot_shipping':
                    $meta['shippingTotal'] = $value;
                    break;
                case 'ot_tax':
                    $tax = ($tax ?? 0) + $value;
                    break;
                case 'ot_discount':
                case 'ot_coupon':
                case 'ot_gv':
                    $discount = ($discount ?? 0) + abs($value);
                    break;
            }
        }
        $meta['taxTotal'] = $tax !== null ? round($tax, 2) : self::num($info['tax'] ?? null);
        $meta['discountTotal'] = $discount !== null ? round($discount, 2) : null;
        if (!isset($meta['shippingTotal'])) {
            $meta['shippingTotal'] = self::num($info['shipping_cost'] ?? null);
        }

        return self::clean($meta);
    }

    private static function arr($v): array
    {
        return is_array($v) ? $v : (is_object($v) ? (array) $v : []);
    }

    private static function str($v): string
    {
        return is_scalar($v) ? trim(html_entity_decode((string) $v, ENT_QUOTES, 'UTF-8')) : '';
    }

    private static function num($v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return round((float) $v, 8);
        }
        if (is_string($v) && is_numeric(trim($v))) {
            return round((float) trim($v), 8);
        }
        return null;
    }

    private static function qty(float $q): string
    {
        return floor($q) == $q ? (string) (int) $q : (string) $q;
    }

    private static function join(array $parts, string $sep = ' '): string
    {
        return implode($sep, array_filter(array_map([self::class, 'str'], $parts), 'strlen'));
    }

    private static function cut(string $s, int $max): string
    {
        return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
    }

    // Drops empty values; strings are trimmed and HTML entities decoded (the shop stores some names encoded).
    private static function clean(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if ($value !== []) {
                    $out[$key] = $value;
                }
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $out[$key] = $value;
                continue;
            }
            $s = self::str($value);
            if ($s !== '') {
                $out[$key] = $s;
            }
        }
        return $out;
    }
}
