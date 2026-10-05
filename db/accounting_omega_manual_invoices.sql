CREATE TABLE IF NOT EXISTS `accounting_omega_manual_invoices` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(128) NOT NULL,
  `invoice_date` date NOT NULL,
  `customer_id` bigint(20) DEFAULT NULL,
  `customer_name` varchar(255) NOT NULL,
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_accounting_omega_manual_invoice_number` (`invoice_number`),
  KEY `ix_accounting_omega_manual_invoice_date` (`invoice_date`),
  KEY `ix_accounting_omega_manual_invoice_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `accounting_omega_manual_invoice_items` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `manual_invoice_id` bigint(20) NOT NULL,
  `order_id` bigint(20) DEFAULT NULL,
  `order_number` varchar(128) NOT NULL,
  `customer_name` varchar(255) NOT NULL,
  `total_eur` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `restored_at` datetime DEFAULT NULL,
  `restored_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_accounting_omega_manual_invoice_order` (`manual_invoice_id`, `order_id`),
  KEY `ix_accounting_omega_manual_invoice_items_order` (`order_id`, `restored_at`),
  CONSTRAINT `fk_accounting_omega_manual_invoice_items_invoice`
    FOREIGN KEY (`manual_invoice_id`) REFERENCES `accounting_omega_manual_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_accounting_omega_manual_invoice_items_order`
    FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
