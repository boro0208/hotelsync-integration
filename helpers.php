<?php

function makeSlug(string $value): string
{
    $value = trim($value);
    $value = strtolower($value);

    $replaceMap = [
        'š' => 's',
        'đ' => 'dj',
        'č' => 'c',
        'ć' => 'c',
        'ž' => 'z',
        'ä' => 'a',
        'ö' => 'o',
        'ü' => 'u',
        'ß' => 'ss',
    ];

    $value = strtr($value, $replaceMap);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim($value, '-');

    return $value;
}

function jsonEncodeSafe($data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function makePayloadHash($data): string
{
    return hash('sha256', jsonEncodeSafe($data));
}

function getNestedValue(array $data, array $possibleKeys, $default = null)
{
    foreach ($possibleKeys as $key) {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }
    }

    return $default;
}

function mapReservationToLocal(array $reservation, string $defaultCurrency = 'EUR'): array
{
    $guestName = trim(
        (isset($reservation['first_name']) ? $reservation['first_name'] : '') . ' ' .
            (isset($reservation['last_name']) ? $reservation['last_name'] : '')
    );

    $externalReservationCode = null;

    if (!empty($reservation['reference'])) {
        $externalReservationCode = $reservation['reference'];
    } elseif (!empty($reservation['external_id'])) {
        $externalReservationCode = $reservation['external_id'];
    }

    $hsReservationId = isset($reservation['id_reservations']) ? (int)$reservation['id_reservations'] : 0;
    $arrivalDate = isset($reservation['date_arrival']) ? $reservation['date_arrival'] : null;

    return [
        'hs_reservation_id' => $hsReservationId,
        'external_reservation_code' => $externalReservationCode,
        'guest_name' => $guestName,
        'guest_email' => isset($reservation['email']) && $reservation['email'] !== '' ? $reservation['email'] : null,
        'arrival_date' => $arrivalDate,
        'departure_date' => isset($reservation['date_departure']) ? $reservation['date_departure'] : null,
        'status' => isset($reservation['status']) ? $reservation['status'] : '',
        'total_amount' => isset($reservation['total_price']) ? (float)$reservation['total_price'] : 0,
        'currency' => isset($reservation['currency']) && $reservation['currency'] !== '' ? $reservation['currency'] : $defaultCurrency,
        'lock_id' => 'LOCK-' . $hsReservationId . '-' . $arrivalDate,
        'payload_hash' => makePayloadHash($reservation),
        'raw_payload' => jsonEncodeSafe($reservation),
    ];
}

function syncReservationRooms(mysqli $connection, int $localReservationId, array $reservationPayload): void
{
    if (!isset($reservationPayload['rooms']) || !is_array($reservationPayload['rooms'])) {
        return;
    }

    $deleteSql = "DELETE FROM reservation_rooms WHERE reservation_id = ?";
    $deleteStmt = mysqli_prepare($connection, $deleteSql);

    if ($deleteStmt) {
        mysqli_stmt_bind_param($deleteStmt, 'i', $localReservationId);
        mysqli_stmt_execute($deleteStmt);
        mysqli_stmt_close($deleteStmt);
    }

    foreach ($reservationPayload['rooms'] as $room) {
        $hsRoomId = isset($room['id_room_types']) ? (int)$room['id_room_types'] : 0;

        if ($hsRoomId <= 0) {
            writeLog(
                'RESERVATION_ROOM_SKIP',
                'Missing id_room_types in reservation room payload',
                jsonEncodeSafe($room)
            );
            continue;
        }

        $findRoomSql = "SELECT id FROM rooms WHERE hs_room_id = ?";
        $findRoomStmt = mysqli_prepare($connection, $findRoomSql);

        if (!$findRoomStmt) {
            writeLog(
                'DB_ERROR',
                'Prepare failed for finding room by hs_room_id: ' . mysqli_error($connection),
                (string)$hsRoomId
            );
            continue;
        }

        mysqli_stmt_bind_param($findRoomStmt, 'i', $hsRoomId);
        mysqli_stmt_execute($findRoomStmt);
        $result = mysqli_stmt_get_result($findRoomStmt);
        $localRoom = mysqli_fetch_assoc($result);
        mysqli_stmt_close($findRoomStmt);

        if (!$localRoom) {
            writeLog(
                'RESERVATION_ROOM_SKIP',
                'Room type not found in local rooms table',
                (string)$hsRoomId
            );
            continue;
        }

        $localRoomId = (int)$localRoom['id'];

        $insertSql = "
            INSERT INTO reservation_rooms (reservation_id, room_id, quantity)
            VALUES (?, ?, ?)
        ";

        $insertStmt = mysqli_prepare($connection, $insertSql);

        if (!$insertStmt) {
            writeLog(
                'DB_ERROR',
                'Prepare failed for reservation_rooms insert: ' . mysqli_error($connection),
                (string)$localReservationId
            );
            continue;
        }

        $quantity = 1;

        mysqli_stmt_bind_param(
            $insertStmt,
            'iii',
            $localReservationId,
            $localRoomId,
            $quantity
        );

        if (!mysqli_stmt_execute($insertStmt)) {
            writeLog(
                'DB_ERROR',
                'Insert failed for reservation_rooms: ' . mysqli_stmt_error($insertStmt),
                (string)$localReservationId
            );
        }

        mysqli_stmt_close($insertStmt);
    }
}

function syncReservationRatePlans(mysqli $connection, int $localReservationId, array $reservationPayload): void
{
    $deleteSql = "DELETE FROM reservation_rate_plans WHERE reservation_id = ?";
    $deleteStmt = mysqli_prepare($connection, $deleteSql);

    if ($deleteStmt) {
        mysqli_stmt_bind_param($deleteStmt, 'i', $localReservationId);
        mysqli_stmt_execute($deleteStmt);
        mysqli_stmt_close($deleteStmt);
    }

    $pricingPlanIds = [];

    if (isset($reservationPayload['id_pricing_plans']) && (int)$reservationPayload['id_pricing_plans'] > 0) {
        $pricingPlanIds[] = (int)$reservationPayload['id_pricing_plans'];
    }

    if (isset($reservationPayload['rooms']) && is_array($reservationPayload['rooms'])) {
        foreach ($reservationPayload['rooms'] as $room) {
            if (!isset($room['nights']) || !is_array($room['nights'])) {
                continue;
            }

            foreach ($room['nights'] as $night) {
                if (isset($night['id_pricing_plans']) && (int)$night['id_pricing_plans'] > 0) {
                    $pricingPlanIds[] = (int)$night['id_pricing_plans'];
                }
            }
        }
    }

    $pricingPlanIds = array_values(array_unique($pricingPlanIds));

    foreach ($pricingPlanIds as $hsRatePlanId) {
        $findRatePlanSql = "SELECT id FROM rate_plans WHERE hs_rate_plan_id = ?";
        $findRatePlanStmt = mysqli_prepare($connection, $findRatePlanSql);

        if (!$findRatePlanStmt) {
            writeLog(
                'DB_ERROR',
                'Prepare failed for finding rate plan by hs_rate_plan_id: ' . mysqli_error($connection),
                (string)$hsRatePlanId
            );
            continue;
        }

        mysqli_stmt_bind_param($findRatePlanStmt, 'i', $hsRatePlanId);
        mysqli_stmt_execute($findRatePlanStmt);
        $result = mysqli_stmt_get_result($findRatePlanStmt);
        $localRatePlan = mysqli_fetch_assoc($result);
        mysqli_stmt_close($findRatePlanStmt);

        if (!$localRatePlan) {
            writeLog(
                'RESERVATION_RATE_PLAN_SKIP',
                'Rate plan not found in local rate_plans table',
                (string)$hsRatePlanId
            );
            continue;
        }

        $localRatePlanId = (int)$localRatePlan['id'];

        $insertSql = "
            INSERT INTO reservation_rate_plans (reservation_id, rate_plan_id)
            VALUES (?, ?)
        ";

        $insertStmt = mysqli_prepare($connection, $insertSql);

        if (!$insertStmt) {
            writeLog(
                'DB_ERROR',
                'Prepare failed for reservation_rate_plans insert: ' . mysqli_error($connection),
                (string)$localReservationId
            );
            continue;
        }

        mysqli_stmt_bind_param(
            $insertStmt,
            'ii',
            $localReservationId,
            $localRatePlanId
        );

        if (!mysqli_stmt_execute($insertStmt)) {
            writeLog(
                'DB_ERROR',
                'Insert failed for reservation_rate_plans: ' . mysqli_stmt_error($insertStmt),
                (string)$localReservationId
            );
        }

        mysqli_stmt_close($insertStmt);
    }
}
