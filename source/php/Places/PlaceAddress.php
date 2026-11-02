<?php

namespace EventManager\Places;

final class PlaceAddress
{
    /** Accept both ACF maps and the frontend map's Schema.org Place. */
    public static function from(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            return null;
        }
        $lat     = $value['lat'] ?? $value['latitude'] ?? null;
        $lng     = $value['lng'] ?? $value['longitude'] ?? null;
        $address = $value['address'] ?? null;
        if (is_array($address)) {
            $value['address_locality'] = $address['addressLocality'] ?? null;
            $value['post_code']        = $address['postalCode'] ?? null;
            $value['country']          = $address['addressCountry'] ?? null;
            $address                   = $address['name'] ?? $address['streetAddress'] ?? null;
        }
        if (is_string($address)) {
            $address = trim(strip_tags($address));
        }
        if (
            !is_string($address) || trim($address) === '' || !is_numeric($lat) || !is_numeric($lng)
            || !is_finite((float) $lat) || !is_finite((float) $lng)
            || abs((float) $lat) > 90 || abs((float) $lng) > 180
        ) {
            return null;
        }
        // Preserve the existing map metadata, including address_locality.
        $value['address'] = trim(strip_tags($address));
        $value['lat']     = (float) $lat;
        $value['lng']     = (float) $lng;
        return $value;
    }

    /** Compact "Street 1, 123 45 City" for labels; falls back to the full stored address. */
    public static function short(array $address): string
    {
        $street = trim(($address['street_name'] ?? '') . ' ' . ($address['street_number'] ?? ''));
        $city   = trim(($address['post_code'] ?? '') . ' ' . ($address['address_locality'] ?? $address['city'] ?? ''));
        $short  = implode(', ', array_filter([$street, $city], fn($part) => $part !== ''));
        return $street !== '' ? $short : (string) $address['address'];
    }

    public static function schema(array $address, string $name, string $details = ''): array
    {
        $place = [
            '@type'     => 'Place',
            'name'      => $name,
            'address'   => $address['address'],
            'latitude'  => $address['lat'],
            'longitude' => $address['lng'],
        ];
        if (trim($details) !== '') {
            $place['description'] = $details;
        }
        return $place;
    }
}
