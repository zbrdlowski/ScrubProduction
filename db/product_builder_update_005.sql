-- Product builder update 005: editable Graphics versions.
-- Import after update 004. Existing listing names, selections and SKUs are preserved.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS product_builder_graphics_versions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(30) NOT NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 seed_key VARCHAR(30) NULL, version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_pb_graphics_version_name (name), UNIQUE KEY uq_pb_graphics_version_seed (seed_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @pb_has_graphics_version = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='product_builder_components' AND column_name='graphics_version_id');
SET @pb_graphics_version_sql = IF(@pb_has_graphics_version=0,
 'ALTER TABLE product_builder_components ADD COLUMN graphics_version_id BIGINT UNSIGNED NULL AFTER design_id', 'DO 0');
PREPARE pb_graphics_version_migration FROM @pb_graphics_version_sql;
EXECUTE pb_graphics_version_migration;
DEALLOCATE PREPARE pb_graphics_version_migration;
SET @pb_has_graphics_version_fk = (SELECT COUNT(*) FROM information_schema.table_constraints
 WHERE constraint_schema=DATABASE() AND table_name='product_builder_components' AND constraint_name='fk_pb_component_graphics_version');
SET @pb_graphics_version_sql = IF(@pb_has_graphics_version_fk=0,
 'ALTER TABLE product_builder_components ADD CONSTRAINT fk_pb_component_graphics_version FOREIGN KEY (graphics_version_id) REFERENCES product_builder_graphics_versions(id) ON DELETE RESTRICT', 'DO 0');
PREPARE pb_graphics_version_migration FROM @pb_graphics_version_sql;
EXECUTE pb_graphics_version_migration;
DEALLOCATE PREPARE pb_graphics_version_migration;
-- Default Graphics versions: stable source keys preserve names and ordering on repeat import.
CREATE TEMPORARY TABLE IF NOT EXISTS pb_deploy_graphics_version_seed (
 name VARCHAR(30) NOT NULL, sort_order INT UNSIGNED NOT NULL, seed_key VARCHAR(30) NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO pb_deploy_graphics_version_seed (name,sort_order,seed_key) VALUES
('ST',1,'default:ST'),
('LE',2,'default:LE'),
('R1',3,'default:R1'),
('R2',4,'default:R2'),
('R3',5,'default:R3'),
('R4',6,'default:R4'),
('R5',7,'default:R5'),
('R6',8,'default:R6'),
('R7',9,'default:R7'),
('R8',10,'default:R8'),
('R9',11,'default:R9'),
('R10',12,'default:R10')
ON DUPLICATE KEY UPDATE name=VALUES(name),sort_order=VALUES(sort_order);
START TRANSACTION;
UPDATE product_builder_graphics_versions existing
JOIN pb_deploy_graphics_version_seed seed ON existing.name=seed.name
LEFT JOIN product_builder_graphics_versions imported ON imported.seed_key=seed.seed_key
SET existing.seed_key=seed.seed_key WHERE existing.seed_key IS NULL AND imported.id IS NULL;
INSERT INTO product_builder_graphics_versions (name,sort_order,seed_key)
SELECT seed.name,seed.sort_order,seed.seed_key FROM pb_deploy_graphics_version_seed seed
WHERE NOT EXISTS (SELECT 1 FROM product_builder_graphics_versions existing WHERE existing.seed_key=seed.seed_key OR existing.name=seed.name);
COMMIT;
