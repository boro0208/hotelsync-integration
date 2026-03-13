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

# Struktura projekta

sync_catalog.php CLI skripta za katalog sync  
sync_reservations.php CLI skripta za import rezervacija  
db.php MySQL konekcija  
api_client.php komunikacija sa HotelSync API  
helpers.php pomoćne funkcije  
logger.php logging sistem  
config.php konfiguracija  
database.sql SQL schema za bazu

---

# Pokretanje projekta

1.  Importovati bazu iz `database.sql`
2.  Podesiti konfiguraciju u fajlu:

config.php

Tu se nalaze:

- MySQL konekcioni podaci
- HotelSync API kredencijali

---

3. Pokretanje Task 1

php sync_catalog.php

---

4. Pokretanje Task 2

php sync_reservations.php --from=2026-01-01 --to=2026-01-31

---

# Trenutno podržano

- Authentication
- Room catalog sync
- Pricing plan sync
- Reservation import
- Reservation to room mapping
- Reservation to rate plan mapping
- Insert / update / skip logika na osnovu payload_hash

---

# Napomena

Task 1 i Task 2 su implementirani.

Ostali taskovi:

Task 3 – Reservation Update / Cancel  
Task 4 – Invoice Creation  
Task 5 – Webhook Endpoint

biće implementirani u narednim koracima.
