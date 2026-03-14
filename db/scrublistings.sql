-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 02, 2026 at 08:20 PM
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
-- Table structure for table `scrub_listings`
--

CREATE TABLE `scrub_listings` (
  `id` int(10) UNSIGNED NOT NULL,
  `listing_code` varchar(32) NOT NULL,
  `listing_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_slovak_ci NOT NULL,
  `model_code` varchar(32) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `discontinued_at` datetime DEFAULT NULL,
  `discontinued_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `scrub_listings`
--

INSERT INTO `scrub_listings` (`id`, `listing_code`, `listing_name`, `model_code`, `price`, `created_at`, `updated_at`, `is_active`, `discontinued_at`, `discontinued_reason`) VALUES
(61, 'P_767001', 'White Honda CRF450R 2017-2018 CRF250R 2018 Plastics Kit', '74PT', 139.90, '2026-03-02 19:15:19', '2026-03-02 19:15:19', 1, NULL, NULL),
(63, 'P_767002', 'Listing No.2', '74PT', 139.90, '2026-03-02 19:15:19', '2026-03-02 19:15:19', 1, NULL, NULL),
(64, 'P_767003', 'dajaký nový listing 4', '74PT', 149.90, '2026-03-02 19:15:19', '2026-03-02 19:15:19', 1, NULL, NULL),
(68, 'P_767004', 'dajaký nový listing 8', '74PT', 149.90, '2026-03-02 19:15:19', '2026-03-02 19:15:19', 1, NULL, NULL),
(72, 'P_767005', 'dajaký nový listing 10', 'TZA3', 149.90, '2026-03-02 19:15:19', '2026-03-02 19:15:19', 1, NULL, NULL),
(74, 'P_767006', 'dajaký nový listing 11', 'G3UP', 139.90, '2026-03-02 19:15:19', '2026-03-02 19:15:19', 1, NULL, NULL),
(75, 'P_767007', 'dajaký nový listing 13', 'G3UP', 139.90, '2026-03-02 19:15:19', '2026-03-02 19:15:19', 1, NULL, NULL),
(77, 'P_767008', 'dajaký nový listing 15', 'G3UP', 139.90, '2026-03-02 19:15:19', '2026-03-02 19:15:19', 1, NULL, NULL),
(79, 'P_767009', 'dajaký nový listing 17', 'YAD2', 139.90, '2026-03-02 19:15:19', '2026-03-02 19:15:19', 1, NULL, NULL),
(81, 'P_767010', 'dajaký nový listing 19', 'YAD2', 139.90, '2026-03-02 19:15:19', '2026-03-02 19:15:19', 1, NULL, NULL),
(83, 'P_767011', 'dajaký nový listing 21', 'YAD2', 139.90, '2026-03-02 19:15:19', '2026-03-02 19:15:19', 1, NULL, NULL),
(85, 'P_767012', 'dajaký nový listing 23', 'NAXW', 139.90, '2026-03-02 19:15:19', '2026-03-02 19:15:19', 1, NULL, NULL),
(87, 'P_767013', 'dajaký nový listing 25', 'NAXW', 139.90, '2026-03-02 19:15:19', '2026-03-02 19:15:19', 1, NULL, NULL),
(89, 'P_767014', 'dajaký nový listing 27', 'NAXW', 139.90, '2026-03-02 19:15:19', '2026-03-02 19:15:19', 1, NULL, NULL),
(91, 'P_767015', 'dajaký nový listing 31', 'VW9Q', 199.90, '2026-03-02 19:15:19', '2026-03-02 19:15:19', 1, NULL, NULL),
(95, 'P_767016', 'dajaký nový listing 35', 'VW9Q', 199.90, '2026-03-02 19:15:19', '2026-03-02 19:15:19', 1, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `scrub_listing_items`
--

CREATE TABLE `scrub_listing_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `listing_id` int(10) UNSIGNED NOT NULL,
  `barcode` varchar(64) NOT NULL,
  `sort_order` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `scrub_listing_items`
--

INSERT INTO `scrub_listing_items` (`id`, `listing_id`, `barcode`, `sort_order`, `created_at`) VALUES
(39, 61, 'HO00633', 1, '2026-03-02 19:15:19'),
(40, 61, 'HO00163', 2, '2026-03-02 19:15:19'),
(41, 63, 'HO00631', 1, '2026-03-02 19:15:19'),
(42, 64, 'HO00631', 1, '2026-03-02 19:15:19'),
(43, 64, 'HO00023', 2, '2026-03-02 19:15:19'),
(44, 64, 'HO00559', 3, '2026-03-02 19:15:19'),
(45, 64, 'HO00571', 4, '2026-03-02 19:15:19'),
(46, 68, 'HO00579', 1, '2026-03-02 19:15:19'),
(47, 68, 'HO00022', 2, '2026-03-02 19:15:19'),
(48, 68, 'HO00558', 3, '2026-03-02 19:15:19'),
(49, 68, 'HO00570', 4, '2026-03-02 19:15:19'),
(50, 72, 'HO00654', 1, '2026-03-02 19:15:19'),
(51, 72, 'HO00163', 2, '2026-03-02 19:15:19'),
(52, 74, 'HO00631', 1, '2026-03-02 19:15:19'),
(53, 75, 'HO00632', 1, '2026-03-02 19:15:19'),
(54, 75, 'HO00023', 2, '2026-03-02 19:15:19'),
(55, 77, 'HO00633', 1, '2026-03-02 19:15:19'),
(56, 77, 'HO00024', 2, '2026-03-02 19:15:19'),
(57, 79, 'HO00002', 1, '2026-03-02 19:15:19'),
(58, 79, 'HO00316', 2, '2026-03-02 19:15:19'),
(59, 81, 'HO00003', 1, '2026-03-02 19:15:19'),
(60, 81, 'HO00323', 2, '2026-03-02 19:15:19'),
(61, 83, 'HO00004', 1, '2026-03-02 19:15:19'),
(62, 83, 'HO00318', 2, '2026-03-02 19:15:19'),
(63, 85, 'HO00002', 1, '2026-03-02 19:15:19'),
(64, 85, 'HO00022', 2, '2026-03-02 19:15:19'),
(65, 87, 'HO00003', 1, '2026-03-02 19:15:19'),
(66, 87, 'HO00023', 2, '2026-03-02 19:15:19'),
(67, 89, 'HO00004', 1, '2026-03-02 19:15:19'),
(68, 89, 'HO00163', 2, '2026-03-02 19:15:19'),
(69, 91, 'HO00536', 1, '2026-03-02 19:15:19'),
(70, 91, 'HO00540', 2, '2026-03-02 19:15:19'),
(71, 91, 'HO00537', 1, '2026-03-02 19:15:19'),
(72, 91, 'HO00539', 2, '2026-03-02 19:15:19'),
(73, 95, 'HO00536', 1, '2026-03-02 19:15:19'),
(74, 95, 'HO00540', 2, '2026-03-02 19:15:19'),
(75, 95, 'HO00537', 3, '2026-03-02 19:15:19'),
(76, 95, 'HO00538', 4, '2026-03-02 19:15:19');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `scrub_listings`
--
ALTER TABLE `scrub_listings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_scrub_listings_code` (`listing_code`),
  ADD KEY `ix_scrub_listings_model_code` (`model_code`);

--
-- Indexes for table `scrub_listing_items`
--
ALTER TABLE `scrub_listing_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_scrub_listing_items_listing_barcode` (`listing_id`,`barcode`),
  ADD KEY `ix_scrub_listing_items_barcode` (`barcode`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `scrub_listings`
--
ALTER TABLE `scrub_listings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=99;

--
-- AUTO_INCREMENT for table `scrub_listing_items`
--
ALTER TABLE `scrub_listing_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `scrub_listing_items`
--
ALTER TABLE `scrub_listing_items`
  ADD CONSTRAINT `fk_scrub_listing_items_listing` FOREIGN KEY (`listing_id`) REFERENCES `scrub_listings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
