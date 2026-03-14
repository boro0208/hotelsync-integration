<?php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/logger.php';

function apiPostRequest(string $endpoint, array $payload, bool $formEncoded = false): array
{
    $config = require __DIR__ . '/config.php';

    $url = rtrim($config['api']['base_url'], '/') . '/' . ltrim($endpoint, '/');

    $ch = curl_init($url);

    $headers = [];

    if ($formEncoded) {
        $body = http_build_query($payload);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } else {
        $body = jsonEncodeSafe($payload);
        $headers[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 30,
    ]);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($responseBody === false || $curlError) {
        writeLog('API_ERROR', 'cURL request failed: ' . $curlError, $endpoint);

        return [
            'success' => false,
            'http_code' => $httpCode,
            'error' => $curlError ?: 'Unknown cURL error',
            'data' => null,
            'raw' => null,
        ];
    }

    $decoded = json_decode($responseBody, true);

    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'error' => null,
        'data' => $decoded,
        'raw' => $responseBody,
    ];
}

function loginToHotelSync(): array
{
    $config = require __DIR__ . '/config.php';

    $payload = [
        'token' => $config['api']['token'],
        'username' => $config['api']['username'],
        'password' => $config['api']['password'],
        'remember' => $config['api']['remember'],
    ];

    $response = apiPostRequest('/user/auth/login', $payload, true);

    if (!$response['success'] || !is_array($response['data'])) {
        return [
            'success' => false,
            'message' => 'Login failed',
            'data' => null,
        ];
    }

    $data = $response['data'];

    $pkey = null;
    if (isset($data['userInf']) && is_array($data['userInf']) && isset($data['userInf']['pkey'])) {
        $pkey = $data['userInf']['pkey'];
    } elseif (isset($data['pkey'])) {
        $pkey = $data['pkey'];
    }

    $propertyId = null;
    if (isset($data['properties']) && is_array($data['properties']) && !empty($data['properties'])) {
        $firstProperty = $data['properties'][0];

        if (isset($firstProperty['id_properties'])) {
            $propertyId = $firstProperty['id_properties'];
        } elseif (isset($firstProperty['id'])) {
            $propertyId = $firstProperty['id'];
        }
    }

    if (!$pkey || !$propertyId) {
        writeLog('AUTH_ERROR', 'Login response missing pkey or property id', 'LOGIN');

        return [
            'success' => false,
            'message' => 'Login response missing pkey or property id',
            'data' => $data,
        ];
    }

    writeLog('AUTH_SUCCESS', 'Successfully logged in to HotelSync API', (string)$propertyId);

    return [
        'success' => true,
        'message' => 'Login successful',
        'data' => [
            'token' => $config['api']['token'],
            'key' => $pkey,
            'id_properties' => $propertyId,
            'raw_login' => $data,
        ],
    ];
}

function fetchRoomTypes(string $token, string $key, int $propertyId): array
{
    $payload = [
        'token' => $token,
        'id_properties' => (string) $propertyId,
        'key' => $key,
        'type' => 1,
        'details' => '1',
    ];

    return apiPostRequest('/room/data/rooms', $payload, false);
}

function fetchPricingPlans(string $token, string $key, int $propertyId): array
{
    $payload = [
        'token' => $token,
        'key' => $key,
        'id_properties' => $propertyId,
    ];

    return apiPostRequest('/pricingPlan/data/pricing_plans', $payload, false);
}

function fetchReservations(string $token, string $key, int $propertyId, string $from, string $to): array
{
    $payload = [
        'token' => $token,
        'key' => $key,
        'id_properties' => $propertyId,
        'channels' => [],
        'countries' => [],
        'order_by' => 'date_received',
        'rooms' => [],
        'arrivals' => 0,
        'companies' => [],
        'contigents' => [],
        'departures' => 0,
        'dfrom' => $from,
        'dto' => $to,
        'last_modified_from' => '',
        'last_modified_to' => '',
        'filter_by' => 'date_arrival',
        'max_nights' => '',
        'max_price' => '',
        'min_nights' => '',
        'min_price' => '',
        'multiple_properties' => '0',
        'offer_expiring' => '0',
        'order_type' => 'desc',
        'page' => 1,
        'pricing_plans' => [],
        'search' => '',
        'show_nights' => 1,
        'show_rooms' => 1,
        'status' => '0',
        'view_type' => 'reservations',
    ];

    return apiPostRequest('/reservation/data/reservations', $payload, false);
}

function fetchReservationById(string $token, string $key, int $propertyId, int $reservationId): array
{
    $payload = [
        'token' => $token,
        'key' => $key,
        'id_properties' => $propertyId,
        'id_reservations' => $reservationId,
    ];

    return apiPostRequest('/reservation/data/reservation', $payload, false);
}
