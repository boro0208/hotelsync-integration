<?php

require __DIR__ . '/db.php';
require __DIR__ . '/logger.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/api_client.php';

echo "Starting catalog sync..." . PHP_EOL;
writeLog('CATALOG_SYNC_START', 'Catalog sync started', 'SYNC_CATALOG');

$loginResponse = loginToHotelSync();

if (!$loginResponse['success']) {
    echo "Login failed." . PHP_EOL;
    writeLog('AUTH_ERROR', 'Login failed in sync_catalog.php', 'SYNC_CATALOG');
    exit(1);
}

$token = $loginResponse['data']['token'];
$key = $loginResponse['data']['key'];
$propertyId = (int) $loginResponse['data']['id_properties'];

echo "Login successful. Property ID: {$propertyId}" . PHP_EOL;

echo PHP_EOL;
echo "Fetching room types..." . PHP_EOL;

$roomsResponse = fetchRoomTypes($token, $key, $propertyId);

if (!$roomsResponse['success'] || !is_array($roomsResponse['data'])) {
    echo "Fetching room types failed." . PHP_EOL;
    writeLog('ROOM_SYNC_ERROR', 'Failed to fetch room types', (string)$propertyId);
    exit(1);
}

$rooms = $roomsResponse['data'];

echo "Total room types fetched: " . count($rooms) . PHP_EOL;

$inserted = 0;
$updated = 0;
$skipped = 0;

foreach ($rooms as $room) {
    $hsRoomId = isset($room['id_room_types']) ? (int) $room['id_room_types'] : 0;
    $roomName = isset($room['name']) ? trim($room['name']) : '';

    if ($hsRoomId <= 0 || $roomName === '') {
        writeLog('ROOM_SYNC_SKIP', 'Missing id_room_types or name', jsonEncodeSafe($room));
        $skipped++;
        continue;
    }

    $roomSlug = makeSlug($roomName);
    $roomCode = 'HS-' . $hsRoomId . '-' . $roomSlug;
    $payloadHash = makePayloadHash($room);
    $rawPayload = jsonEncodeSafe($room);

    $selectSql = "SELECT id, payload_hash FROM rooms WHERE hs_room_id = ?";
    $selectStmt = mysqli_prepare($connection, $selectSql);

    if (!$selectStmt) {
        writeLog('DB_ERROR', 'Prepare failed for room select: ' . mysqli_error($connection), (string)$hsRoomId);
        $skipped++;
        continue;
    }

    mysqli_stmt_bind_param($selectStmt, 'i', $hsRoomId);
    mysqli_stmt_execute($selectStmt);
    $result = mysqli_stmt_get_result($selectStmt);
    $existingRoom = mysqli_fetch_assoc($result);
    mysqli_stmt_close($selectStmt);

    if (!$existingRoom) {
        $insertSql = "
            INSERT INTO rooms (hs_room_id, room_name, room_slug, room_code, payload_hash, raw_payload)
            VALUES (?, ?, ?, ?, ?, ?)
        ";

        $insertStmt = mysqli_prepare($connection, $insertSql);

        if (!$insertStmt) {
            writeLog('DB_ERROR', 'Prepare failed for room insert: ' . mysqli_error($connection), (string)$hsRoomId);
            $skipped++;
            continue;
        }

        mysqli_stmt_bind_param(
            $insertStmt,
            'isssss',
            $hsRoomId,
            $roomName,
            $roomSlug,
            $roomCode,
            $payloadHash,
            $rawPayload
        );

        if (mysqli_stmt_execute($insertStmt)) {
            $inserted++;
            writeLog('ROOM_INSERT', 'Inserted room: ' . $roomName, (string)$hsRoomId);
        } else {
            writeLog('DB_ERROR', 'Room insert failed: ' . mysqli_stmt_error($insertStmt), (string)$hsRoomId);
            $skipped++;
        }

        mysqli_stmt_close($insertStmt);
        continue;
    }

    if ($existingRoom['payload_hash'] === $payloadHash) {
        $skipped++;
        writeLog('ROOM_SKIP', 'No changes for room: ' . $roomName, (string)$hsRoomId);
        continue;
    }

    $updateSql = "
        UPDATE rooms
        SET room_name = ?, room_slug = ?, room_code = ?, payload_hash = ?, raw_payload = ?
        WHERE hs_room_id = ?
    ";

    $updateStmt = mysqli_prepare($connection, $updateSql);

    if (!$updateStmt) {
        writeLog('DB_ERROR', 'Prepare failed for room update: ' . mysqli_error($connection), (string)$hsRoomId);
        $skipped++;
        continue;
    }

    mysqli_stmt_bind_param(
        $updateStmt,
        'sssssi',
        $roomName,
        $roomSlug,
        $roomCode,
        $payloadHash,
        $rawPayload,
        $hsRoomId
    );

    if (mysqli_stmt_execute($updateStmt)) {
        $updated++;
        writeLog('ROOM_UPDATE', 'Updated room: ' . $roomName, (string)$hsRoomId);
    } else {
        writeLog('DB_ERROR', 'Room update failed: ' . mysqli_stmt_error($updateStmt), (string)$hsRoomId);
        $skipped++;
    }

    mysqli_stmt_close($updateStmt);
}

echo "Room sync finished. Inserted: {$inserted}, Updated: {$updated}, Skipped: {$skipped}" . PHP_EOL;

echo PHP_EOL;
echo "Fetching pricing plans..." . PHP_EOL;

$pricingPlansResponse = fetchPricingPlans($token, $key, $propertyId);

if (!$pricingPlansResponse['success'] || !is_array($pricingPlansResponse['data'])) {
    echo "Fetching pricing plans failed." . PHP_EOL;
    writeLog('RATE_PLAN_SYNC_ERROR', 'Failed to fetch pricing plans', (string)$propertyId);
    exit(1);
}

$pricingPlans = $pricingPlansResponse['data'];

echo "Total pricing plans fetched: " . count($pricingPlans) . PHP_EOL;

$pricingInserted = 0;
$pricingUpdated = 0;
$pricingSkipped = 0;

foreach ($pricingPlans as $plan) {
    $hsRatePlanId = isset($plan['id_pricing_plans']) ? (int) $plan['id_pricing_plans'] : 0;
    $ratePlanName = isset($plan['name']) ? trim($plan['name']) : '';

    if ($hsRatePlanId <= 0 || $ratePlanName === '') {
        writeLog('RATE_PLAN_SKIP', 'Missing id_pricing_plans or name', jsonEncodeSafe($plan));
        $pricingSkipped++;
        continue;
    }

    $mealPlan = 'no-meal';

    if (isset($plan['first_meal']) && $plan['first_meal'] !== null && trim((string)$plan['first_meal']) !== '') {
        $mealPlan = makeSlug((string) $plan['first_meal']);
    }

    $ratePlanCode = 'RP-' . $hsRatePlanId . '-' . $mealPlan;
    $payloadHash = makePayloadHash($plan);
    $rawPayload = jsonEncodeSafe($plan);

    $selectSql = "SELECT id, payload_hash FROM rate_plans WHERE hs_rate_plan_id = ?";
    $selectStmt = mysqli_prepare($connection, $selectSql);

    if (!$selectStmt) {
        writeLog('DB_ERROR', 'Prepare failed for rate plan select: ' . mysqli_error($connection), (string)$hsRatePlanId);
        $pricingSkipped++;
        continue;
    }

    mysqli_stmt_bind_param($selectStmt, 'i', $hsRatePlanId);
    mysqli_stmt_execute($selectStmt);
    $result = mysqli_stmt_get_result($selectStmt);
    $existingPlan = mysqli_fetch_assoc($result);
    mysqli_stmt_close($selectStmt);

    if (!$existingPlan) {
        $insertSql = "
            INSERT INTO rate_plans (
                hs_rate_plan_id,
                rate_plan_name,
                meal_plan,
                rate_plan_code,
                payload_hash,
                raw_payload
            ) VALUES (?, ?, ?, ?, ?, ?)
        ";

        $insertStmt = mysqli_prepare($connection, $insertSql);

        if (!$insertStmt) {
            writeLog('DB_ERROR', 'Prepare failed for rate plan insert: ' . mysqli_error($connection), (string)$hsRatePlanId);
            $pricingSkipped++;
            continue;
        }

        mysqli_stmt_bind_param(
            $insertStmt,
            'isssss',
            $hsRatePlanId,
            $ratePlanName,
            $mealPlan,
            $ratePlanCode,
            $payloadHash,
            $rawPayload
        );

        if (mysqli_stmt_execute($insertStmt)) {
            $pricingInserted++;
            writeLog('RATE_PLAN_INSERT', 'Inserted rate plan: ' . $ratePlanName, (string)$hsRatePlanId);
        } else {
            writeLog('DB_ERROR', 'Rate plan insert failed: ' . mysqli_stmt_error($insertStmt), (string)$hsRatePlanId);
            $pricingSkipped++;
        }

        mysqli_stmt_close($insertStmt);
        continue;
    }

    if ($existingPlan['payload_hash'] === $payloadHash) {
        $pricingSkipped++;
        writeLog('RATE_PLAN_SKIP', 'No changes for rate plan: ' . $ratePlanName, (string)$hsRatePlanId);
        continue;
    }

    $updateSql = "
        UPDATE rate_plans
        SET rate_plan_name = ?, meal_plan = ?, rate_plan_code = ?, payload_hash = ?, raw_payload = ?
        WHERE hs_rate_plan_id = ?
    ";

    $updateStmt = mysqli_prepare($connection, $updateSql);

    if (!$updateStmt) {
        writeLog('DB_ERROR', 'Prepare failed for rate plan update: ' . mysqli_error($connection), (string)$hsRatePlanId);
        $pricingSkipped++;
        continue;
    }

    mysqli_stmt_bind_param(
        $updateStmt,
        'sssssi',
        $ratePlanName,
        $mealPlan,
        $ratePlanCode,
        $payloadHash,
        $rawPayload,
        $hsRatePlanId
    );

    if (mysqli_stmt_execute($updateStmt)) {
        $pricingUpdated++;
        writeLog('RATE_PLAN_UPDATE', 'Updated rate plan: ' . $ratePlanName, (string)$hsRatePlanId);
    } else {
        writeLog('DB_ERROR', 'Rate plan update failed: ' . mysqli_stmt_error($updateStmt), (string)$hsRatePlanId);
        $pricingSkipped++;
    }

    mysqli_stmt_close($updateStmt);
}

echo "Pricing plan sync finished. Inserted: {$pricingInserted}, Updated: {$pricingUpdated}, Skipped: {$pricingSkipped}" . PHP_EOL;

echo PHP_EOL;
echo "CATALOG SYNC FINISHED" . PHP_EOL;

writeLog(
    'CATALOG_SYNC_FINISH',
    'Catalog sync finished. Rooms inserted=' . $inserted .
        ', rooms updated=' . $updated .
        ', rooms skipped=' . $skipped .
        ', pricing inserted=' . $pricingInserted .
        ', pricing updated=' . $pricingUpdated .
        ', pricing skipped=' . $pricingSkipped,
    'SYNC_CATALOG'
);
