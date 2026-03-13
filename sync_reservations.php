<?php

require __DIR__ . '/db.php';
require __DIR__ . '/logger.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/api_client.php';

echo "Starting reservation sync..." . PHP_EOL;
writeLog('RESERVATION_SYNC_START', 'Reservation sync started', 'SYNC_RESERVATIONS');

$options = getopt('', ['from:', 'to:']);

if (!isset($options['from']) || !isset($options['to'])) {
    echo "Usage: php sync_reservations.php --from=YYYY-MM-DD --to=YYYY-MM-DD" . PHP_EOL;
    exit(1);
}

$from = $options['from'];
$to = $options['to'];

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    echo "Invalid date format. Use YYYY-MM-DD." . PHP_EOL;
    exit(1);
}

echo "Date range: {$from} -> {$to}" . PHP_EOL;

$loginResponse = loginToHotelSync();

if (!$loginResponse['success']) {
    echo "Login failed." . PHP_EOL;
    writeLog('AUTH_ERROR', 'Login failed in sync_reservations.php', 'SYNC_RESERVATIONS');
    exit(1);
}

$token = $loginResponse['data']['token'];
$key = $loginResponse['data']['key'];
$propertyId = (int) $loginResponse['data']['id_properties'];

echo "Login successful. Property ID: {$propertyId}" . PHP_EOL;
echo "Fetching reservations..." . PHP_EOL;

$reservationsResponse = fetchReservations($token, $key, $propertyId, $from, $to);

if (!$reservationsResponse['success'] || !is_array($reservationsResponse['data'])) {
    echo "Fetching reservations failed." . PHP_EOL;
    print_r($reservationsResponse);
    writeLog('RESERVATION_SYNC_ERROR', 'Failed to fetch reservations', 'SYNC_RESERVATIONS');
    exit(1);
}

$data = $reservationsResponse['data'];

$totalPages = isset($data['total_pages_number']) ? $data['total_pages_number'] : 0;
$page = isset($data['page']) ? $data['page'] : 0;
$currency = isset($data['currency']) ? $data['currency'] : '';
$reservations = isset($data['reservations']) && is_array($data['reservations']) ? $data['reservations'] : [];

echo "Reservations fetched successfully." . PHP_EOL;
echo "Page: {$page}" . PHP_EOL;
echo "Total pages: {$totalPages}" . PHP_EOL;
echo "Currency: {$currency}" . PHP_EOL;
echo "Total reservations returned: " . count($reservations) . PHP_EOL;

writeLog(
    'RESERVATION_SYNC_FETCH_OK',
    'Reservations fetched successfully. Count=' . count($reservations),
    'SYNC_RESERVATIONS'
);


$inserted = 0;
$updated = 0;
$skipped = 0;

foreach ($reservations as $reservation) {
    $mappedReservation = mapReservationToLocal($reservation, $currency);

    if (
        $mappedReservation['hs_reservation_id'] <= 0 ||
        empty($mappedReservation['guest_name']) ||
        empty($mappedReservation['arrival_date']) ||
        empty($mappedReservation['departure_date']) ||
        empty($mappedReservation['status'])
    ) {
        writeLog(
            'RESERVATION_SKIP',
            'Missing required reservation fields',
            jsonEncodeSafe($reservation)
        );
        $skipped++;
        continue;
    }

    $selectSql = "SELECT id, payload_hash FROM reservations WHERE hs_reservation_id = ?";
    $selectStmt = mysqli_prepare($connection, $selectSql);

    if (!$selectStmt) {
        writeLog(
            'DB_ERROR',
            'Prepare failed for reservation select: ' . mysqli_error($connection),
            (string)$mappedReservation['hs_reservation_id']
        );
        $skipped++;
        continue;
    }

    mysqli_stmt_bind_param($selectStmt, 'i', $mappedReservation['hs_reservation_id']);
    mysqli_stmt_execute($selectStmt);
    $result = mysqli_stmt_get_result($selectStmt);
    $existingReservation = mysqli_fetch_assoc($result);
    mysqli_stmt_close($selectStmt);

    if (!$existingReservation) {
        $insertSql = "
            INSERT INTO reservations (
                hs_reservation_id,
                external_reservation_code,
                guest_name,
                guest_email,
                arrival_date,
                departure_date,
                status,
                total_amount,
                currency,
                lock_id,
                payload_hash,
                raw_payload
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $insertStmt = mysqli_prepare($connection, $insertSql);

        if (!$insertStmt) {
            writeLog(
                'DB_ERROR',
                'Prepare failed for reservation insert: ' . mysqli_error($connection),
                (string)$mappedReservation['hs_reservation_id']
            );
            $skipped++;
            continue;
        }

        mysqli_stmt_bind_param(
            $insertStmt,
            'issssssdssss',
            $mappedReservation['hs_reservation_id'],
            $mappedReservation['external_reservation_code'],
            $mappedReservation['guest_name'],
            $mappedReservation['guest_email'],
            $mappedReservation['arrival_date'],
            $mappedReservation['departure_date'],
            $mappedReservation['status'],
            $mappedReservation['total_amount'],
            $mappedReservation['currency'],
            $mappedReservation['lock_id'],
            $mappedReservation['payload_hash'],
            $mappedReservation['raw_payload']
        );

        if (mysqli_stmt_execute($insertStmt)) {
            $localReservationId = mysqli_insert_id($connection);

            syncReservationRooms($connection, $localReservationId, $reservation);

            syncReservationRatePlans($connection, $localReservationId, $reservation);

            $inserted++;
            writeLog(
                'RESERVATION_INSERT',
                'Inserted reservation',
                (string)$mappedReservation['hs_reservation_id']
            );
        } else {
            writeLog(
                'DB_ERROR',
                'Reservation insert failed: ' . mysqli_stmt_error($insertStmt),
                (string)$mappedReservation['hs_reservation_id']
            );
            $skipped++;
        }

        mysqli_stmt_close($insertStmt);
        continue;
    }

    if ($existingReservation['payload_hash'] === $mappedReservation['payload_hash']) {
        $skipped++;
        writeLog(
            'RESERVATION_SKIP',
            'No changes for reservation',
            (string)$mappedReservation['hs_reservation_id']
        );
        continue;
    }

    $updateSql = "
        UPDATE reservations
        SET
            external_reservation_code = ?,
            guest_name = ?,
            guest_email = ?,
            arrival_date = ?,
            departure_date = ?,
            status = ?,
            total_amount = ?,
            currency = ?,
            lock_id = ?,
            payload_hash = ?,
            raw_payload = ?
        WHERE hs_reservation_id = ?
    ";

    $updateStmt = mysqli_prepare($connection, $updateSql);

    if (!$updateStmt) {
        writeLog(
            'DB_ERROR',
            'Prepare failed for reservation update: ' . mysqli_error($connection),
            (string)$mappedReservation['hs_reservation_id']
        );
        $skipped++;
        continue;
    }

    mysqli_stmt_bind_param(
        $updateStmt,
        'ssssssdssssi',
        $mappedReservation['external_reservation_code'],
        $mappedReservation['guest_name'],
        $mappedReservation['guest_email'],
        $mappedReservation['arrival_date'],
        $mappedReservation['departure_date'],
        $mappedReservation['status'],
        $mappedReservation['total_amount'],
        $mappedReservation['currency'],
        $mappedReservation['lock_id'],
        $mappedReservation['payload_hash'],
        $mappedReservation['raw_payload'],
        $mappedReservation['hs_reservation_id']
    );

    if (mysqli_stmt_execute($updateStmt)) {
        $findLocalSql = "SELECT id FROM reservations WHERE hs_reservation_id = ?";
        $findLocalStmt = mysqli_prepare($connection, $findLocalSql);

        if ($findLocalStmt) {
            mysqli_stmt_bind_param($findLocalStmt, 'i', $mappedReservation['hs_reservation_id']);
            mysqli_stmt_execute($findLocalStmt);
            $localResult = mysqli_stmt_get_result($findLocalStmt);
            $localReservation = mysqli_fetch_assoc($localResult);
            mysqli_stmt_close($findLocalStmt);

            if ($localReservation) {
                $localReservationId = (int)$localReservation['id'];

                syncReservationRooms($connection, $localReservationId, $reservation);
                syncReservationRatePlans($connection, $localReservationId, $reservation);
            }
        }

        $updated++;
        writeLog(
            'RESERVATION_UPDATE',
            'Updated reservation',
            (string)$mappedReservation['hs_reservation_id']
        );
    } else {
        writeLog(
            'DB_ERROR',
            'Reservation update failed: ' . mysqli_stmt_error($updateStmt),
            (string)$mappedReservation['hs_reservation_id']
        );
        $skipped++;
    }

    mysqli_stmt_close($updateStmt);
}

echo PHP_EOL;
echo "RESERVATION MAIN SYNC FINISHED" . PHP_EOL;
echo "Inserted: {$inserted}" . PHP_EOL;
echo "Updated: {$updated}" . PHP_EOL;
echo "Skipped: {$skipped}" . PHP_EOL;

writeLog(
    'RESERVATION_SYNC_MAIN_FINISH',
    'Reservation main sync finished. Inserted=' . $inserted . ', Updated=' . $updated . ', Skipped=' . $skipped,
    'SYNC_RESERVATIONS'
);
