-- MiniDash — rejestr sesji logowania
--
-- Każda zalogowana sesja przeglądarki dostaje wiersz. Dzięki temu da się zobaczyć,
-- kto jest zalogowany, i zakończyć obcą sesję: revoked_at wylogowuje ją przy
-- najbliższym żądaniu, a remember_selector pozwala skasować jej token
-- „zapamiętaj mnie" (inaczej zalogowałaby się z powrotem sama).

CREATE TABLE IF NOT EXISTS user_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sid TEXT NOT NULL UNIQUE,
    username TEXT NOT NULL,
    ip TEXT,
    location TEXT,
    os TEXT,
    browser TEXT,
    user_agent TEXT,
    via TEXT,                       -- 'login' | 'remember' | 'existing'
    remember_selector TEXT,
    suspicious INTEGER NOT NULL DEFAULT 0,
    suspicious_reason TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME
);

CREATE INDEX IF NOT EXISTS idx_user_sessions_last_seen ON user_sessions(last_seen);
