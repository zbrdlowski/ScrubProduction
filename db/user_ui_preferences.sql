-- Per-user UI preferences shared across Darkscrub pages.
ALTER TABLE employees
    ADD COLUMN IF NOT EXISTS ui_preferences_json LONGTEXT
        CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
        DEFAULT NULL
        AFTER permission;
