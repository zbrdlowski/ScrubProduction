-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 08, 2026 at 08:33 PM
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
  `priority` tinyint(4) NOT NULL DEFAULT 0,
  `priority_date` date DEFAULT NULL COMMENT 'Dátum pre Deadline / Priority úrovne',
  `currency` varchar(8) DEFAULT NULL,
  `total` decimal(12,2) DEFAULT NULL,
  `payment_method` varchar(64) DEFAULT NULL,
  `shipping_method` varchar(64) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `source_meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`source_meta`)),
  `customer_id` bigint(20) DEFAULT NULL,
  `manual_types_override` varchar(20) DEFAULT NULL,
  `manual_types_updated_by` int(11) DEFAULT NULL,
  `manual_types_updated_at` datetime DEFAULT NULL,
  `production_note` text DEFAULT NULL,
  `production_note_updated_by` int(11) DEFAULT NULL,
  `production_note_updated_at` datetime DEFAULT NULL,
  `traffic_light` varchar(20) DEFAULT 'RED',
  `traffic_blocker` varchar(5) DEFAULT NULL,
  `traffic_summary_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`traffic_summary_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--


-- --------------------------------------------------------

--
-- Table structure for table `order_activity`
--

CREATE TABLE `order_activity` (
  `id` bigint(20) NOT NULL,
  `order_id` bigint(20) DEFAULT NULL,
  `actor_employee_id` int(11) DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `entity_type` varchar(64) DEFAULT NULL,
  `entity_id` bigint(20) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_activity`
--


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
  `company_id` varchar(255) DEFAULT NULL,
  `street` varchar(255) DEFAULT NULL,
  `city` varchar(128) DEFAULT NULL,
  `zip` varchar(32) DEFAULT NULL,
  `country` varchar(2) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_addresses`
--


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

--
-- Dumping data for table `order_assignments`
--


-- --------------------------------------------------------

--
-- Table structure for table `order_categories`
--

CREATE TABLE `order_categories` (
  `order_id` bigint(20) NOT NULL,
  `category_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_categories`
--


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

--
-- Dumping data for table `order_invoices`
--


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
  `unit_price` decimal(10,2) DEFAULT NULL COMMENT 'Jednotková cena — editovateľná, pre zľavy / príplatky',
  `options_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`options_json`)),
  `deleted_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `status` varchar(50) DEFAULT 'NEW',
  `waiting_note` text DEFAULT NULL,
  `expected_date` date DEFAULT NULL,
  `completed_by` int(11) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `product_url` text DEFAULT NULL,
  `internal_options_json` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--


-- --------------------------------------------------------

--
-- Table structure for table `order_item_assignments`
--

CREATE TABLE `order_item_assignments` (
  `id` bigint(20) NOT NULL,
  `order_id` bigint(20) NOT NULL,
  `item_id` bigint(20) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `removed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_item_assignments`
--


-- --------------------------------------------------------

--
-- Table structure for table `order_item_categories`
--

CREATE TABLE `order_item_categories` (
  `item_id` bigint(20) NOT NULL,
  `category_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_item_categories`
--


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

--
-- Dumping data for table `order_item_statuses`
--


-- --------------------------------------------------------

--
-- Table structure for table `order_sources`
--

CREATE TABLE `order_sources` (
  `id` int(11) NOT NULL,
  `code` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_sources`
--

INSERT INTO `order_sources` (`id`, `code`) VALUES
(1, 'EBAY'),
(3, 'MX_LOCKER'),
(2, 'SHOPTET');

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

--
-- Dumping data for table `order_tracking_numbers`
--


-- --------------------------------------------------------

--
-- Table structure for table `product_spec_options`
--

CREATE TABLE `product_spec_options` (
  `id` int(11) NOT NULL,
  `spec_key` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `value` varchar(100) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `color` varchar(20) DEFAULT NULL,
  `department` enum('G','S','P','F') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `product_spec_options`
--

INSERT INTO `product_spec_options` (`id`, `spec_key`, `label`, `value`, `sort_order`, `active`, `created_at`, `updated_at`, `color`, `department`) VALUES
(1, 'graphics_material', 'Standard', 'Standard', 10, 1, '2026-06-04 13:17:47', '2026-06-04 13:38:21', NULL, NULL),
(2, 'graphics_material', 'Chrome', 'Chrome', 20, 1, '2026-06-04 13:17:47', '2026-06-04 13:38:28', NULL, NULL),
(3, 'graphics_material', 'Holochrome', 'Holochrome', 30, 1, '2026-06-04 13:17:47', '2026-06-04 13:38:36', NULL, NULL),
(4, 'graphics_finish', 'Gloss', 'Gloss', 10, 1, '2026-06-04 13:17:47', '2026-06-04 13:47:10', NULL, NULL),
(5, 'graphics_finish', 'Matte', 'Matte', 20, 1, '2026-06-04 13:17:47', '2026-06-04 13:47:26', NULL, NULL),
(6, 'graphics_finish', 'Frozen', 'Frozen', 30, 1, '2026-06-04 13:17:47', '2026-06-04 13:47:33', NULL, NULL),
(7, 'graphics_grip', '✗', '0', 10, 1, '2026-06-04 13:17:47', '2026-06-06 18:01:13', NULL, NULL),
(8, 'graphics_grip', '✓', '1', 20, 1, '2026-06-04 13:17:47', '2026-06-04 14:54:40', NULL, NULL),
(10, 'graphics_tr_swingarms', '✗', '✗', 10, 1, '2026-06-04 13:17:47', '2026-06-04 13:57:44', NULL, NULL),
(11, 'graphics_tr_swingarms', 'RIP', 'RIP', 20, 1, '2026-06-04 13:17:47', '2026-06-04 13:57:53', NULL, NULL),
(12, 'graphics_tr_swingarms', '✓', '✓', 30, 1, '2026-06-04 13:17:47', '2026-06-04 13:58:03', NULL, NULL),
(13, 'graphics_printer', 'Old', 'Old', 10, 1, '2026-06-04 13:17:47', '2026-06-04 14:00:52', NULL, NULL),
(14, 'graphics_printer', 'New', 'New', 20, 1, '2026-06-04 13:17:47', '2026-06-04 14:00:48', NULL, NULL),
(16, 'seat_waterproof_seams', '✗', '0', 20, 1, '2026-06-04 13:17:47', '2026-06-07 09:36:11', NULL, NULL),
(17, 'seat_waterproof_seams', '✓', '1', 30, 1, '2026-06-04 13:17:47', '2026-06-07 09:36:17', NULL, NULL),
(18, 'seat_enduro_pocket', '✗', '0', 20, 1, '2026-06-04 13:17:47', '2026-06-07 09:36:38', NULL, NULL),
(19, 'seat_enduro_pocket', '✓', '1', 30, 1, '2026-06-04 13:17:47', '2026-06-07 09:36:42', NULL, NULL),
(20, 'seat_side_brand_patches', '✗', '0', 20, 1, '2026-06-04 13:17:47', '2026-06-07 09:37:00', NULL, NULL),
(21, 'seat_side_brand_patches', '✓', '1', 30, 1, '2026-06-04 13:17:47', '2026-06-07 09:37:04', NULL, NULL),
(22, 'graphics_material', 'Fluo yellow', 'Fluo yellow', 40, 1, '2026-06-04 13:39:22', '2026-06-04 13:43:42', NULL, NULL),
(24, 'graphics_material', 'Fluo orange', 'Fluo orange', 50, 1, '2026-06-04 13:42:25', '2026-06-04 13:43:45', NULL, NULL),
(25, 'graphics_material', 'Fluo Green', 'Fluo Green', 60, 1, '2026-06-04 13:42:32', '2026-06-04 13:43:49', NULL, NULL),
(26, 'graphics_material', 'Fluo Pink', 'Fluo Pink', 70, 1, '2026-06-04 13:42:37', '2026-06-04 13:43:54', NULL, NULL),
(27, 'graphics_material', 'More Layers', 'More Layers', 80, 1, '2026-06-04 13:42:44', '2026-06-04 13:43:57', NULL, NULL),
(28, 'graphics_material', 'Silver metalilc', 'Silver metalilc', 90, 1, '2026-06-04 13:42:52', '2026-06-04 13:44:01', NULL, NULL),
(29, 'graphics_material', 'Special', 'Special', 100, 1, '2026-06-04 13:43:06', '2026-06-04 13:44:07', NULL, NULL),
(30, 'graphics_material', 'Transparent', 'Transparent', 110, 1, '2026-06-04 13:44:47', '2026-06-04 13:44:47', NULL, NULL),
(31, 'graphics_material', 'Reflex', 'Reflex', 120, 0, '2026-06-04 13:44:57', '2026-06-04 13:44:57', NULL, NULL),
(32, 'graphics_material', 'White - AP/10', 'White - AP/10', 130, 1, '2026-06-04 13:45:11', '2026-06-04 13:45:11', NULL, NULL),
(33, 'graphics_material', 'White - AP/90', 'White - AP/90', 140, 1, '2026-06-04 13:45:21', '2026-06-04 13:45:21', NULL, NULL),
(34, 'graphics_material', 'White - AP/90 - BKX', 'White - AP/90 - BKX', 150, 1, '2026-06-04 13:45:31', '2026-06-04 13:45:31', NULL, NULL),
(35, 'graphics_material', 'White SUB - 660L', 'White SUB - 660L', 160, 1, '2026-06-04 13:45:40', '2026-06-04 13:45:40', NULL, NULL),
(36, 'graphics_material', 'White SUB - X1', 'White SUB - X1', 170, 1, '2026-06-04 13:45:57', '2026-06-04 13:45:57', NULL, NULL),
(37, 'graphics_material', 'White SUB - X2', 'White SUB - X2', 180, 1, '2026-06-04 13:46:10', '2026-06-04 13:46:10', NULL, NULL),
(38, 'graphics_finish', 'MF - Holo', 'MF - Holo', 40, 1, '2026-06-04 13:47:45', '2026-06-04 13:47:45', NULL, NULL),
(39, 'graphics_finish', 'MF - Silver', 'MF - Silver', 50, 1, '2026-06-04 13:52:41', '2026-06-04 13:52:41', NULL, NULL),
(40, 'graphics_finish', 'MF - Gold', 'MF - Gold', 60, 1, '2026-06-04 13:52:58', '2026-06-04 13:52:58', NULL, NULL),
(41, 'graphics_finish', 'Pixel', 'Pixel', 70, 1, '2026-06-04 13:53:28', '2026-06-04 13:53:28', NULL, NULL),
(42, 'graphics_finish', 'Satin', 'Satin', 80, 1, '2026-06-04 13:53:38', '2026-06-04 13:53:38', NULL, NULL),
(43, 'graphics_finish', 'Prizm', 'Prizm', 90, 1, '2026-06-04 13:53:47', '2026-06-04 13:53:47', NULL, NULL),
(44, 'graphics_finish', 'Bloom', 'Bloom', 100, 1, '2026-06-04 13:53:57', '2026-06-04 13:53:57', NULL, NULL),
(45, 'graphics_finish', 'Hive', 'Hive', 110, 1, '2026-06-04 13:54:07', '2026-06-04 13:54:07', NULL, NULL),
(46, 'graphics_finish', 'Gloss - APA L/11', 'Gloss - APA L/11', 120, 1, '2026-06-04 13:54:25', '2026-06-04 13:54:25', NULL, NULL),
(47, 'graphics_finish', 'Gloss - APA L/12', 'Gloss - APA L/12', 130, 1, '2026-06-04 13:54:41', '2026-06-04 13:54:41', NULL, NULL),
(48, 'graphics_finish', 'Gloss - APA L/13', 'Gloss - APA L/13', 140, 1, '2026-06-04 13:54:54', '2026-06-04 13:54:54', NULL, NULL),
(49, 'graphics_finish', 'Gloss - SUB 1000', 'Gloss - SUB 1000', 150, 1, '2026-06-04 13:55:05', '2026-06-04 13:55:05', NULL, NULL),
(50, 'graphics_finish', 'Gloss - SUB 1500', 'Gloss - SUB 1500', 160, 1, '2026-06-04 13:55:18', '2026-06-04 13:55:18', NULL, NULL),
(51, 'graphics_finish', 'Gloss - Frozen', 'Gloss - Frozen', 170, 1, '2026-06-04 13:55:56', '2026-06-04 13:55:56', NULL, NULL),
(52, 'graphics_tr_swingarms', 'None', 'None', 40, 1, '2026-06-04 13:58:16', '2026-06-04 13:59:27', NULL, NULL),
(53, 'graphics_grip', 'None', 'None', 30, 1, '2026-06-04 14:54:29', '2026-06-04 14:54:29', NULL, NULL),
(54, 'seat_waterproof_seams', 'NONE', 'NONE', 10, 1, '2026-06-07 09:36:07', '2026-06-07 09:36:07', NULL, NULL),
(55, 'seat_enduro_pocket', 'None', 'NONE', 10, 1, '2026-06-07 09:36:34', '2026-06-07 09:36:34', NULL, NULL),
(56, 'seat_side_brand_patches', 'NONE', 'NONE', 10, 1, '2026-06-07 09:36:56', '2026-06-07 09:36:56', NULL, NULL),
(57, 'graphics_draft', '✗', '✗', 10, 1, '2026-06-07 12:13:05', '2026-06-07 12:13:43', NULL, 'G'),
(58, 'graphics_draft', '✓', '✓', 20, 1, '2026-06-07 12:13:40', '2026-06-07 12:13:40', NULL, 'G');

-- --------------------------------------------------------

--
-- Table structure for table `status_definitions`
--

CREATE TABLE `status_definitions` (
  `id` int(11) NOT NULL,
  `scope` enum('order','item') NOT NULL,
  `department` enum('G','S','P','F') DEFAULT NULL,
  `code` varchar(64) NOT NULL,
  `label` varchar(100) NOT NULL,
  `color` varchar(20) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_final` tinyint(1) NOT NULL DEFAULT 0,
  `is_waiting` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `status_definitions`
--

INSERT INTO `status_definitions` (`id`, `scope`, `department`, `code`, `label`, `color`, `sort_order`, `is_final`, `is_waiting`, `active`, `created_at`, `updated_at`) VALUES
(1, 'order', NULL, 'NEW', 'New', '#17a2b8', 10, 0, 0, 1, '2026-06-05 20:03:33', '2026-06-05 20:03:33'),
(2, 'order', NULL, 'IN_PROGRESS', 'In Progress', '#ffc107', 20, 0, 0, 1, '2026-06-05 20:03:33', '2026-06-05 20:03:33'),
(3, 'order', NULL, 'READY_TO_SHIP', 'Ready to Ship', '#28a745', 30, 0, 0, 1, '2026-06-05 20:03:33', '2026-06-05 20:03:33'),
(4, 'order', NULL, 'NEED_INFO', 'Need Info', '#dc3545', 40, 0, 0, 1, '2026-06-05 20:03:33', '2026-06-05 20:03:33'),
(5, 'item', 'G', 'RTP', 'RTP', '#17a2b8', 10, 0, 0, 1, '2026-06-05 20:03:33', '2026-06-05 20:03:33'),
(6, 'item', 'G', 'PRINT_QUEUE', 'Print Queue', '#6f42c1', 20, 0, 0, 1, '2026-06-05 20:03:33', '2026-06-05 20:03:33'),
(7, 'item', 'G', 'PRINTED', 'Printed', '#20c997', 30, 0, 0, 1, '2026-06-05 20:03:33', '2026-06-05 20:03:33'),
(8, 'item', 'G', 'CUT', 'Cut', '#fd7e14', 40, 0, 0, 1, '2026-06-05 20:03:33', '2026-06-05 20:03:33'),
(9, 'item', 'G', 'READY', 'Ready', '#28a745', 50, 0, 0, 1, '2026-06-05 20:03:33', '2026-06-05 20:03:33'),
(10, 'item', 'G', 'WAITING', 'Waiting', '#dc3545', 60, 0, 0, 1, '2026-06-05 20:03:33', '2026-06-05 20:03:33'),
(11, 'item', 'P', 'PROCESSING', 'Processing', '#ffc107', 10, 0, 0, 1, '2026-06-05 20:03:33', '2026-06-05 20:03:33'),
(12, 'item', 'P', 'READY', 'Ready', '#28a745', 20, 0, 0, 1, '2026-06-05 20:03:33', '2026-06-05 20:03:33'),
(13, 'item', 'P', 'WAITING', 'Waiting', '#dc3545', 30, 0, 0, 1, '2026-06-05 20:03:33', '2026-06-05 20:03:33'),
(14, 'order', NULL, 'READY_TO_INVOICE', 'Ready to Invoice', '#ffccbb', 50, 0, 0, 1, '2026-06-05 20:46:31', '2026-06-05 20:47:16'),
(15, 'order', NULL, 'DRAFT_READY', 'Draft Ready', '#dc25cc', 60, 0, 0, 1, '2026-06-05 20:50:32', '2026-06-06 18:01:32'),
(16, 'item', 'F', 'REPRINT', 'Reprint', '#ad2587', 0, 0, 0, 1, '2026-06-07 09:11:17', '2026-06-07 09:12:37'),
(17, 'item', 'F', 'READY', 'Ready', '#aabbaa', 0, 0, 0, 1, '2026-06-07 09:11:17', '2026-06-07 09:12:57'),
(18, 'item', 'P', 'NEED_INFO', 'Need Info', '#bbffcc', 0, 0, 0, 1, '2026-06-07 09:11:48', '2026-06-07 09:12:03'),
(19, 'item', 'S', 'NEED_INFO', 'Need Info', NULL, 0, 0, 0, 1, '2026-06-07 09:13:26', '2026-06-07 09:13:26'),
(20, 'item', 'S', 'READY', 'Ready', NULL, 0, 0, 0, 1, '2026-06-07 09:20:23', '2026-06-07 09:20:23');

-- --------------------------------------------------------

--
-- Table structure for table `status_workflow_rules`
--

CREATE TABLE `status_workflow_rules` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `result_order_status_code` varchar(64) NOT NULL,
  `priority` int(11) NOT NULL DEFAULT 100,
  `stop_on_match` tinyint(1) NOT NULL DEFAULT 1,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `status_workflow_rules`
--

INSERT INTO `status_workflow_rules` (`id`, `name`, `description`, `result_order_status_code`, `priority`, `stop_on_match`, `active`, `notes`, `created_at`, `updated_at`) VALUES
(1, 'Ready to ship when G and P are ready', NULL, 'READY_TO_SHIP', 10, 1, 1, NULL, '2026-06-05 20:03:43', '2026-06-05 20:03:43'),
(2, 'Need info when any dept is waiting', NULL, 'NEED_INFO', 20, 1, 1, NULL, '2026-06-05 20:03:43', '2026-06-05 20:03:43'),
(3, 'GFPS Ready', 'GFPS Ready -> Ready to Invoice. All deparments Ready means, invoice can be created', 'READY_TO_INVOICE', 100, 1, 1, NULL, '2026-06-07 21:20:50', '2026-06-07 21:20:50');

-- --------------------------------------------------------

--
-- Table structure for table `status_workflow_rule_conditions`
--

CREATE TABLE `status_workflow_rule_conditions` (
  `id` int(11) NOT NULL,
  `rule_id` int(11) NOT NULL,
  `department` enum('G','S','P','F') NOT NULL,
  `condition_type` varchar(20) NOT NULL DEFAULT 'status',
  `operator` enum('=','!=','IN','NOT IN') NOT NULL DEFAULT '=',
  `status_code` varchar(64) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `status_workflow_rule_conditions`
--

INSERT INTO `status_workflow_rule_conditions` (`id`, `rule_id`, `department`, `condition_type`, `operator`, `status_code`, `sort_order`) VALUES
(1, 3, 'G', 'status', 'IN', 'READY', 10),
(2, 3, 'P', 'status', 'IN', 'READY', 20),
(3, 3, 'S', 'status', 'IN', 'READY', 30),
(4, 3, 'F', 'status', 'IN', 'READY', 40);

--
-- Indexes for dumped tables
--

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
  ADD UNIQUE KEY `uq_order_type` (`order_id`,`type`),
  ADD KEY `idx_order_addresses_company_id` (`company_id`);

--
-- Indexes for table `order_assignments`
--
ALTER TABLE `order_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_order_employee` (`order_id`,`employee_id`),
  ADD UNIQUE KEY `uq_order_employee_role` (`order_id`,`employee_id`,`role`),
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
-- Indexes for table `order_item_assignments`
--
ALTER TABLE `order_item_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_oia_item_employee` (`item_id`,`employee_id`),
  ADD KEY `ix_oia_order` (`order_id`),
  ADD KEY `ix_oia_item` (`item_id`),
  ADD KEY `ix_oia_employee` (`employee_id`),
  ADD KEY `fk_oia_by` (`assigned_by`);

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
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_oist_item` (`order_item_id`),
  ADD KEY `ix_oist_changed_by` (`changed_by`),
  ADD KEY `ix_oist_changed_at` (`changed_at`);

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
-- Indexes for table `product_spec_options`
--
ALTER TABLE `product_spec_options`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `status_definitions`
--
ALTER TABLE `status_definitions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_status_scope_dept_code` (`scope`,`department`,`code`),
  ADD KEY `idx_status_scope_dept_active` (`scope`,`department`,`active`,`sort_order`);

--
-- Indexes for table `status_workflow_rules`
--
ALTER TABLE `status_workflow_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_workflow_rules_active_priority` (`active`,`priority`,`id`);

--
-- Indexes for table `status_workflow_rule_conditions`
--
ALTER TABLE `status_workflow_rule_conditions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rule_conditions_rule` (`rule_id`,`sort_order`,`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=811;

--
-- AUTO_INCREMENT for table `order_activity`
--
ALTER TABLE `order_activity`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=748;

--
-- AUTO_INCREMENT for table `order_addresses`
--
ALTER TABLE `order_addresses`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1621;

--
-- AUTO_INCREMENT for table `order_assignments`
--
ALTER TABLE `order_assignments`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=110;

--
-- AUTO_INCREMENT for table `order_invoices`
--
ALTER TABLE `order_invoices`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1494;

--
-- AUTO_INCREMENT for table `order_item_assignments`
--
ALTER TABLE `order_item_assignments`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=99;

--
-- AUTO_INCREMENT for table `order_item_statuses`
--
ALTER TABLE `order_item_statuses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=142;

--
-- AUTO_INCREMENT for table `order_sources`
--
ALTER TABLE `order_sources`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `order_tracking_numbers`
--
ALTER TABLE `order_tracking_numbers`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `product_spec_options`
--
ALTER TABLE `product_spec_options`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=59;

--
-- AUTO_INCREMENT for table `status_definitions`
--
ALTER TABLE `status_definitions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `status_workflow_rules`
--
ALTER TABLE `status_workflow_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `status_workflow_rule_conditions`
--
ALTER TABLE `status_workflow_rule_conditions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

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
-- Constraints for table `order_item_assignments`
--
ALTER TABLE `order_item_assignments`
  ADD CONSTRAINT `fk_oia_by` FOREIGN KEY (`assigned_by`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `fk_oia_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `fk_oia_item` FOREIGN KEY (`item_id`) REFERENCES `order_items` (`id`),
  ADD CONSTRAINT `fk_oia_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`);

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
-- Constraints for table `status_workflow_rule_conditions`
--
ALTER TABLE `status_workflow_rule_conditions`
  ADD CONSTRAINT `fk_rule_conditions_rule` FOREIGN KEY (`rule_id`) REFERENCES `status_workflow_rules` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
