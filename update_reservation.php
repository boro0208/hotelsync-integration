<?php

require __DIR__ . '/db.php';
require __DIR__ . '/logger.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/api_client.php';

echo "Starting reservation update..." . PHP_EOL;
writeLog('RESERVATION_UPDATE_START', 'Reservation update started', 'UPDATE_RESERVATION');

$options = getopt('', ['reservation_id:']);

if (!isset($options['reservation_id'])) {
    echo "Usage: php update_reservation.php --reservation_id=XXXX" . PHP_EOL;
    exit(1);
}

$reservationId = (int)$options['reservation_id'];

if ($reservationId <= 0) {
    echo "Invalid reservation_id." . PHP_EOL;
    exit(1);
}

echo "Reservation ID: {$reservationId}" . PHP_EOL;

$loginResponse = loginToHotelSync();

if (!$loginResponse['success']) {
    echo "Login failed." . PHP_EOL;
    print_r($loginResponse);
    writeLog('AUTH_ERROR', 'Login failed in update_reservation.php', 'UPDATE_RESERVATION');
    exit(1);
}

$token = $loginResponse['data']['token'];
$key = $loginResponse['data']['key'];
$propertyId = (int)$loginResponse['data']['id_properties'];

echo "Login successful. Property ID: {$propertyId}" . PHP_EOL;
echo "Fetching reservation..." . PHP_EOL;

$reservationResponse = fetchReservationById($token, $key, $propertyId, $reservationId);

if (!$reservationResponse['success'] || !is_array($reservationResponse['data'])) {
    echo "Fetching reservation failed." . PHP_EOL;
    print_r($reservationResponse);
    writeLog('RESERVATION_FETCH_ERROR', 'Failed to fetch reservation by ID', (string)$reservationId);
    exit(1);
}

$reservation = $reservationResponse['data'];
$mappedReservation = mapReservationToLocal($reservation);

echo "Reservation fetched successfully." . PHP_EOL;

$selectSql = "SELECT id, payload_hash, status FROM reservations WHERE hs_reservation_id = ?";
$selectStmt = mysqli_prepare($connection, $selectSql);

if (!$selectStmt) {
    echo "Failed to prepare local reservation lookup." . PHP_EOL;
    writeLog('DB_ERROR', 'Prepare failed for local reservation lookup: ' . mysqli_error($connection), (string)$reservationId);
    exit(1);
}

mysqli_stmt_bind_param($selectStmt, 'i', $mappedReservation['hs_reservation_id']);
mysqli_stmt_execute($selectStmt);
$result = mysqli_stmt_get_result($selectStmt);
$existingReservation = mysqli_fetch_assoc($result);
mysqli_stmt_close($selectStmt);

if (!$existingReservation) {
    echo "Reservation does not exist in local database." . PHP_EOL;

    insertAuditLog(
        $connection,
        $mappedReservation['hs_reservation_id'],
        'not_found',
        null,
        $mappedReservation['payload_hash'],
        'Reservation not found in local database during update.'
    );

    writeLog(
        'RESERVATION_NOT_FOUND',
        'Reservation not found in local database.',
        (string)$mappedReservation['hs_reservation_id']
    );

    exit(0);
}

$localReservationId = (int)$existingReservation['id'];
$oldPayloadHash = $existingReservation['payload_hash'];
$newPayloadHash = $mappedReservation['payload_hash'];

if ($oldPayloadHash === $newPayloadHash) {
    echo "No changes detected. Skipping update." . PHP_EOL;

    insertAuditLog(
        $connection,
        $mappedReservation['hs_reservation_id'],
        'no_change',
        $oldPayloadHash,
        $newPayloadHash,
        'No changes detected for reservation.'
    );

    writeLog(
        'RESERVATION_NO_CHANGE',
        'No changes detected.',
        (string)$mappedReservation['hs_reservation_id']
    );

    exit(0);
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
    echo "Failed to prepare reservation update." . PHP_EOL;
    writeLog('DB_ERROR', 'Prepare failed for reservation update: ' . mysqli_error($connection), (string)$mappedReservation['hs_reservation_id']);
    exit(1);
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

if (!mysqli_stmt_execute($updateStmt)) {
    echo "Reservation update failed." . PHP_EOL;
    writeLog(
        'DB_ERROR',
        'Reservation update failed: ' . mysqli_stmt_error($updateStmt),
        (string)$mappedReservation['hs_reservation_id']
    );
    mysqli_stmt_close($updateStmt);
    exit(1);
}

mysqli_stmt_close($updateStmt);

syncReservationRooms($connection, $localReservationId, $reservation);
syncReservationRatePlans($connection, $localReservationId, $reservation);

$eventType = 'updated';
$message = 'Reservation updated successfully.';

if (isset($mappedReservation['status']) && strtolower($mappedReservation['status']) === 'cancelled') {
    $eventType = 'cancelled';
    $message = 'Reservation was cancelled and kept in local database.';
}

insertAuditLog(
    $connection,
    $mappedReservation['hs_reservation_id'],
    $eventType,
    $oldPayloadHash,
    $newPayloadHash,
    $message
);

echo "Reservation updated successfully." . PHP_EOL;
echo "Event type: {$eventType}" . PHP_EOL;

writeLog(
    'RESERVATION_UPDATED',
    'Reservation updated successfully. Event type: ' . $eventType,
    (string)$mappedReservation['hs_reservation_id']
);
