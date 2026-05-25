CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NULL,
    type TEXT NOT NULL,
    app TEXT NULL,
    environment TEXT NULL,
    payload TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT UNIQUE NOT NULL,
    app TEXT NULL,
    environment TEXT NULL,
    method TEXT NULL,
    path TEXT NULL,
    uri TEXT NULL,
    status INTEGER NULL,
    duration_ms REAL NULL,
    memory_peak INTEGER NULL,
    is_slow INTEGER DEFAULT 0,
    started_at TEXT NULL,
    ended_at TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NULL,
    level TEXT NOT NULL,
    message TEXT NOT NULL,
    context TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS spans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NULL,
    span_id TEXT NULL,
    name TEXT NOT NULL,
    duration_ms REAL NULL,
    started_at TEXT NULL,
    ended_at TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS errors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NULL,
    type TEXT NULL,
    message TEXT NOT NULL,
    file TEXT NULL,
    line INTEGER NULL,
    stacktrace TEXT NULL,
    context TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS database_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NULL,
    driver TEXT NULL,
    operation TEXT NOT NULL,
    tables TEXT NULL,
    sql TEXT NOT NULL,
    bindings TEXT NULL,
    source_file TEXT NULL,
    source_line INTEGER NULL,
    source_class TEXT NULL,
    source_function TEXT NULL,
    source_confidence TEXT NULL,
    affected_rows INTEGER NULL,
    duration_ms REAL NULL,
    is_mutation INTEGER DEFAULT 0,
    is_slow INTEGER DEFAULT 0,
    is_error INTEGER DEFAULT 0,
    error_class TEXT NULL,
    error_message TEXT NULL,
    error_code TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_events_request_id ON events(request_id);
CREATE INDEX IF NOT EXISTS idx_events_created_at ON events(created_at);
CREATE INDEX IF NOT EXISTS idx_requests_request_id ON requests(request_id);
CREATE INDEX IF NOT EXISTS idx_requests_created_at ON requests(created_at);
CREATE INDEX IF NOT EXISTS idx_logs_request_id ON logs(request_id);
CREATE INDEX IF NOT EXISTS idx_spans_request_id ON spans(request_id);
CREATE INDEX IF NOT EXISTS idx_errors_request_id ON errors(request_id);
CREATE INDEX IF NOT EXISTS idx_database_events_request_id ON database_events(request_id);
