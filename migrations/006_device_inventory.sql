-- MiniDash — inwentarz urządzeń
--
-- Dla każdego widzianego w sieci urządzenia (MAC) można zapisać właściciela i notatkę
-- oraz je „zatwierdzić". Urządzenie bez wpisu albo z approved=0 jest traktowane jako
-- NOWE/niezatwierdzone - dzięki temu widać sprzęt, który pojawił się bez wiedzy admina.
-- Nazwa i data pierwszego widzenia pochodzą z known_macs.json, tu trzymamy tylko
-- dane dokładane przez człowieka plus znacznik zatwierdzenia.

CREATE TABLE IF NOT EXISTS device_inventory (
    mac         TEXT PRIMARY KEY,
    owner       TEXT,
    note        TEXT,
    approved    INTEGER NOT NULL DEFAULT 0,
    approved_at DATETIME,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);
