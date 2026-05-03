-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 03, 2026 at 05:39 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `scrubproduction`
--

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `code` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` bigint(20) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` bigint(20) NOT NULL,
  `order_id` bigint(20) NOT NULL,
  `invoice_number` varchar(64) NOT NULL,
  `issued_at` datetime DEFAULT NULL,
  `total` decimal(12,2) DEFAULT NULL,
  `currency` varchar(8) DEFAULT NULL,
  `source` varchar(32) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` bigint(20) NOT NULL,
  `source_id` int(11) NOT NULL,
  `external_order_id` varchar(128) NOT NULL,
  `order_number` varchar(64) DEFAULT NULL,
  `imported_at` datetime NOT NULL,
  `order_date` datetime DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'NEW',
  `manual_types_override` varchar(20) DEFAULT NULL,
  `manual_types_updated_by` int(11) DEFAULT NULL,
  `manual_types_updated_at` datetime DEFAULT NULL,
  `production_note` text DEFAULT NULL,
  `production_note_updated_by` int(11) DEFAULT NULL,
  `production_note_updated_at` datetime DEFAULT NULL,
  `priority` tinyint(4) NOT NULL DEFAULT 0,
  `currency` varchar(8) DEFAULT NULL,
  `total` decimal(12,2) DEFAULT NULL,
  `payment_method` varchar(64) DEFAULT NULL,
  `shipping_method` varchar(64) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `source_meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`source_meta`)),
  `customer_id` bigint(20) DEFAULT NULL,
  `traffic_light` varchar(20) DEFAULT 'RED',
  `traffic_blocker` varchar(5) DEFAULT NULL,
  `traffic_summary_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`traffic_summary_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_activity`
--

CREATE TABLE `order_activity` (
  `id` bigint(20) NOT NULL,
  `order_id` bigint(20) NOT NULL,
  `actor_employee_id` int(11) DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `entity_type` varchar(64) DEFAULT NULL,
  `entity_id` bigint(20) DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_addresses`
--

CREATE TABLE `order_addresses` (
  `id` bigint(20) NOT NULL,
  `order_id` bigint(20) NOT NULL,
  `type` varchar(16) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `company` varchar(255) DEFAULT NULL,
  `street` varchar(255) DEFAULT NULL,
  `city` varchar(128) DEFAULT NULL,
  `zip` varchar(32) DEFAULT NULL,
  `country` varchar(2) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_assignments`
--

CREATE TABLE `order_assignments` (
  `id` bigint(20) NOT NULL,
  `order_id` bigint(20) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `role` varchar(32) NOT NULL,
  `state` varchar(32) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `invited_by` int(11) DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `accepted_at` datetime DEFAULT NULL,
  `removed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_categories`
--

CREATE TABLE `order_categories` (
  `order_id` bigint(20) NOT NULL,
  `category_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_invoices`
--

CREATE TABLE `order_invoices` (
  `id` bigint(20) NOT NULL,
  `order_id` bigint(20) NOT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` bigint(20) NOT NULL,
  `order_id` bigint(20) NOT NULL,
  `line_no` int(11) DEFAULT NULL,
  `sku` varchar(128) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `custom_label` varchar(255) DEFAULT NULL,
  `item_type_code` varchar(10) DEFAULT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `options_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`options_json`)),
  `deleted_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `status` varchar(50) DEFAULT 'NEW',
  `waiting_note` text DEFAULT NULL,
  `expected_date` date DEFAULT NULL,
  `completed_by` int(11) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_item_categories`
--

CREATE TABLE `order_item_categories` (
  `item_id` bigint(20) NOT NULL,
  `category_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_item_statuses`
--

CREATE TABLE `order_item_statuses` (
  `id` int(11) NOT NULL,
  `order_item_id` int(11) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) NOT NULL,
  `note` text DEFAULT NULL,
  `expected_date` date DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_sources`
--

CREATE TABLE `order_sources` (
  `id` int(11) NOT NULL,
  `code` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_tracking_numbers`
--

CREATE TABLE `order_tracking_numbers` (
  `id` bigint(20) NOT NULL,
  `order_id` bigint(20) NOT NULL,
  `tracking_number` varchar(120) NOT NULL,
  `carrier` varchar(80) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shipments`
--

CREATE TABLE `shipments` (
  `id` bigint(20) NOT NULL,
  `order_id` bigint(20) NOT NULL,
  `carrier` varchar(64) DEFAULT NULL,
  `tracking_number` varchar(128) DEFAULT NULL,
  `shipped_at` datetime DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'PENDING',
  `source` varchar(32) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_email` (`email`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_invoice_number` (`invoice_number`),
  ADD KEY `fk_inv_order` (`order_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_source_external` (`source_id`,`external_order_id`),
  ADD KEY `ix_status` (`status`),
  ADD KEY `fk_orders_customer` (`customer_id`),
  ADD KEY `ix_orders_order_number` (`order_number`),
  ADD KEY `ix_orders_external_order_id` (`external_order_id`),
  ADD KEY `ix_orders_production_note_updated_by` (`production_note_updated_by`),
  ADD KEY `ix_orders_manual_types_updated_by` (`manual_types_updated_by`);

--
-- Indexes for table `order_activity`
--
ALTER TABLE `order_activity`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_act_emp` (`actor_employee_id`),
  ADD KEY `ix_act_order` (`order_id`),
  ADD KEY `ix_act_time` (`created_at`),
  ADD KEY `ix_act_entity` (`entity_type`,`entity_id`);

--
-- Indexes for table `order_addresses`
--
ALTER TABLE `order_addresses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_order_type` (`order_id`,`type`);

--
-- Indexes for table `order_assignments`
--
ALTER TABLE `order_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_order_employee` (`order_id`,`employee_id`),
  ADD KEY `fk_oa_emp` (`employee_id`),
  ADD KEY `fk_oa_assigned_by` (`assigned_by`),
  ADD KEY `fk_oa_invited_by` (`invited_by`),
  ADD KEY `idx_order_role_active` (`order_id`,`role`,`removed_at`),
  ADD KEY `idx_order_employee_active` (`order_id`,`employee_id`,`removed_at`);

--
-- Indexes for table `order_categories`
--
ALTER TABLE `order_categories`
  ADD PRIMARY KEY (`order_id`,`category_id`),
  ADD KEY `fk_oc_cat` (`category_id`);

--
-- Indexes for table `order_invoices`
--
ALTER TABLE `order_invoices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_oinv_order` (`order_id`),
  ADD KEY `ix_oinv_number` (`invoice_number`),
  ADD KEY `ix_oinv_created_by` (`created_by`),
  ADD KEY `ix_oinv_deleted` (`deleted_at`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_order` (`order_id`),
  ADD KEY `ix_order_items_deleted_at` (`deleted_at`),
  ADD KEY `ix_order_items_created_by` (`created_by`),
  ADD KEY `ix_order_items_updated_by` (`updated_by`);

--
-- Indexes for table `order_item_categories`
--
ALTER TABLE `order_item_categories`
  ADD PRIMARY KEY (`item_id`,`category_id`),
  ADD KEY `fk_oic_cat` (`category_id`);

--
-- Indexes for table `order_item_statuses`
--
ALTER TABLE `order_item_statuses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `order_sources`
--
ALTER TABLE `order_sources`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `order_tracking_numbers`
--
ALTER TABLE `order_tracking_numbers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_tracking_order_number_active` (`order_id`,`tracking_number`,`deleted_at`),
  ADD KEY `ix_otn_order` (`order_id`),
  ADD KEY `ix_otn_tracking` (`tracking_number`),
  ADD KEY `ix_otn_created_by` (`created_by`),
  ADD KEY `ix_otn_deleted` (`deleted_at`);

--
-- Indexes for table `shipments`
--
ALTER TABLE `shipments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_tracking` (`tracking_number`),
  ADD KEY `ix_ship_order` (`order_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_activity`
--
ALTER TABLE `order_activity`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_addresses`
--
ALTER TABLE `order_addresses`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_assignments`
--
ALTER TABLE `order_assignments`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_invoices`
--
ALTER TABLE `order_invoices`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_item_statuses`
--
ALTER TABLE `order_item_statuses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_sources`
--
ALTER TABLE `order_sources`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_tracking_numbers`
--
ALTER TABLE `order_tracking_numbers`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shipments`
--
ALTER TABLE `shipments`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `fk_inv_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`);

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `fk_orders_manual_types_updated_by` FOREIGN KEY (`manual_types_updated_by`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `fk_orders_production_note_updated_by` FOREIGN KEY (`production_note_updated_by`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `fk_orders_source` FOREIGN KEY (`source_id`) REFERENCES `order_sources` (`id`);

--
-- Constraints for table `order_activity`
--
ALTER TABLE `order_activity`
  ADD CONSTRAINT `fk_act_emp` FOREIGN KEY (`actor_employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `fk_act_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`);

--
-- Constraints for table `order_addresses`
--
ALTER TABLE `order_addresses`
  ADD CONSTRAINT `fk_addr_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`);

--
-- Constraints for table `order_assignments`
--
ALTER TABLE `order_assignments`
  ADD CONSTRAINT `fk_oa_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `fk_oa_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `fk_oa_invited_by` FOREIGN KEY (`invited_by`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `fk_oa_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`);

--
-- Constraints for table `order_categories`
--
ALTER TABLE `order_categories`
  ADD CONSTRAINT `fk_oc_cat` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  ADD CONSTRAINT `fk_oc_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`);

--
-- Constraints for table `order_invoices`
--
ALTER TABLE `order_invoices`
  ADD CONSTRAINT `fk_oinv_created_by` FOREIGN KEY (`created_by`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `fk_oinv_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`);

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `fk_item_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `fk_order_items_created_by` FOREIGN KEY (`created_by`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `fk_order_items_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `employees` (`id`);

--
-- Constraints for table `order_item_categories`
--
ALTER TABLE `order_item_categories`
  ADD CONSTRAINT `fk_oic_cat` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  ADD CONSTRAINT `fk_oic_item` FOREIGN KEY (`item_id`) REFERENCES `order_items` (`id`);

--
-- Constraints for table `order_tracking_numbers`
--
ALTER TABLE `order_tracking_numbers`
  ADD CONSTRAINT `fk_otn_created_by` FOREIGN KEY (`created_by`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `fk_otn_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`);

--
-- Constraints for table `shipments`
--
ALTER TABLE `shipments`
  ADD CONSTRAINT `fk_ship_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
