-- Pending customer-contact history; safe to run repeatedly.
-- Independent of import data and the removable order_activity log.
CREATE TABLE IF NOT EXISTS order_pending_contacts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    actor_employee_id INT UNSIGNED NOT NULL,
    contacted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    channel VARCHAR(20) NOT NULL,
    outcome VARCHAR(32) NOT NULL,
    note TEXT NOT NULL,
    next_contact_at DATETIME NULL DEFAULT NULL,
    request_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (id),
    KEY idx_pending_contact_order (order_id, id),
    UNIQUE KEY uq_pending_contact_request (request_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
