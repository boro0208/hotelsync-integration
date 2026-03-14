# HotelSync Integration – Technical Task

Mini integracioni servis između **HotelSync API-ja** i lokalnog sistema **BridgeOne**.

## Tehnologije

- PHP (proceduralni pristup)
- MySQL
- mysqli
- cURL

---

# Implementirano

## Task 1 – Authentication & Catalog Sync

CLI skripta:

php sync_catalog.php

Skripta radi sledeće:

1. Autentifikacija prema HotelSync API-ju
2. Preuzimanje room types
3. Preuzimanje pricing plans
4. Mapiranje podataka u lokalnu MySQL bazu
5. Insert novih zapisa
6. Update postojećih zapisa ako se payload promeni
7. Generisanje lokalnih kodova

    Room code:
    HS-{ROOM_ID}-{slug_room_name}

    Rate plan code:
    RP-{RATE_PLAN_ID}-{meal_plan}

8. Logging događaja u log fajl

---

## Task 2 – Reservation Import

CLI skripta:

php sync_reservations.php --from=2026-01-01 --to=2026-01-31

Skripta radi sledeće:

1. Preuzimanje rezervacija iz HotelSync API-ja za zadati period
2. Mapiranje glavnih podataka rezervacije u lokalnu bazu
3. Insert novih rezervacija
4. Update postojećih rezervacija ako se payload promeni
5. Skip rezervacija bez promena
6. Generisanje lock_id u formatu:

    LOCK-{reservation_id}-{arrival_date}

7. Mapiranje povezanih soba u tabelu reservation_rooms
8. Mapiranje povezanih rate planova u tabelu reservation_rate_plans
9. Logging događaja u log fajl

---

## Task 3 – Reservation Update / Cancel

CLI skripta:

php update_reservation.php --reservation_id=XXXX

Skripta radi sledeće:

1. Preuzimanje jedne rezervacije iz HotelSync API-ja
2. Provera da li rezervacija postoji lokalno
3. Poređenje payload hash vrednosti
4. Ako postoji promena – ažuriranje lokalnih podataka
5. Ažuriranje povezanih soba i rate planova
6. Upis događaja u audit_logs tabelu
7. Ako je rezervacija otkazana, ostaje u bazi i beleži se audit događaj

---

# Task 4 – Invoice Creation (Planned)

Invoice generation bi bio implementiran kroz posebnu CLI skriptu:

php generate_invoice.php --reservation_id=XXXX

Planirana logika:

1. učitati rezervaciju iz lokalne baze
2. generisati invoice payload (guest, datumi, line items, total)
3. generisati invoice broj u formatu:

    HS-INV-YYYY-000001

4. upisati fakturu u tabelu `invoice_queue`
5. implementirati retry mehanizam (do 5 pokušaja) ako slanje fakture ne uspe

---

# Task 5 – Webhook Endpoint (Planned)

Webhook endpoint bi bio implementiran kao:

POST /webhooks/otasync.php

Planirana logika:

1. primiti webhook payload
2. validirati podatke
3. izračunati payload hash
4. proveriti da li je event već obrađen
5. ažurirati rezervaciju u lokalnoj bazi

Na taj način bi se obradili događaji:

- nova rezervacija
- izmena rezervacije
- otkazivanje rezervacije

---

# Struktura projekta

- sync_catalog.php → CLI skripta za katalog sync
- sync_reservations.php → CLI skripta za import rezervacija
- update_reservation.php → CLI skripta za update jedne rezervacije

- db.php → MySQL konekcija
- api_client.php → komunikacija sa HotelSync API
- helpers.php → pomoćne funkcije
- logger.php → logging sistem

- config.local.php → lokalna konfiguracija (nije u repo)
- config.example.php → primer konfiguracije

- database.sql → SQL schema za bazu

---

# Pokretanje projekta

1.  Import baze

    Importovati bazu iz fajla: database.sql

2.  Podesiti konfiguraciju

    Kopirati konfiguraciju: config.example.php → config.php

    U config.local.php se nalaze:

        MySQL konekcioni podaci
        HotelSync API kredencijali

3.  Pokretanje Task 1

    php sync_catalog.php

4.  Pokretanje Task 2

    php sync_reservations.php --from=2026-01-01 --to=2026-01-31

5.  Pokretanje Task 3

    php update_reservation.php --reservation_id=XXXX

---

# Trenutno podržano

- Authentication
- Room catalog sync
- Pricing plan sync
- Reservation import
- Reservation to room mapping
- Reservation to rate plan mapping
- Reservation update / no-change detection
- Audit logging
- Insert / update / skip logika na osnovu payload_hash

---

# Napomena

Task 1, Task 2 i Task 3 su implementirani i funkcionalni.

Za Task 4 – **Invoice Creation** i Task 5 – **Webhook Endpoint** postoji početni skeleton u kodu (CLI skripta i struktura endpointa), ali kompletna logika implementacije nije završena.

U README fajlu je opisan planirani pristup implementaciji ovih taskova.
