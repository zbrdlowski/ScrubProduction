-- Upgrade existing contact tracking; safe to run repeatedly.
-- Preserve all contact history while allowing cancellation requests without a follow-up.
ALTER TABLE order_pending_contacts
    MODIFY COLUMN next_contact_at DATETIME NULL DEFAULT NULL;
