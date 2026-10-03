-- MiniDash — historia WAN per łącze
--
-- wan_stats trzymało jeden wiersz na cykl pollingu, zawsze z wan1. Ruch po WAN2
-- nie trafiał do bazy w ogóle, więc ani wykres historyczny, ani raport nie mogły
-- go pokazać. wan_idx rozdziela łącza, up pozwala policzyć dostępność w oknie czasu.
--
-- wan_idx = 0 zarezerwowane dla wiersza zbiorczego (suma wszystkich łączy).
-- Istniejące wiersze dostają 1, bo tym właśnie były.

ALTER TABLE wan_stats ADD COLUMN wan_idx INTEGER NOT NULL DEFAULT 1;
ALTER TABLE wan_stats ADD COLUMN up INTEGER NOT NULL DEFAULT 1;

CREATE INDEX IF NOT EXISTS idx_wan_stats_idx_recorded ON wan_stats(wan_idx, recorded_at);
