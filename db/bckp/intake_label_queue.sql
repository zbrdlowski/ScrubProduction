-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Dec 30, 2025 at 04:50 PM
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
-- Table structure for table `intake_label_queue`
--

CREATE TABLE `intake_label_queue` (
  `id` int(11) NOT NULL,
  `intake_ref` varchar(50) CHARACTER SET utf8 COLLATE utf8_czech_ci NOT NULL,
  `barcode` varchar(25) CHARACTER SET utf8 COLLATE utf8_czech_ci NOT NULL,
  `item_name` varchar(150) CHARACTER SET utf8 COLLATE utf8_czech_ci DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `shelf_location` varchar(20) CHARACTER SET utf8 COLLATE utf8_czech_ci DEFAULT 'A010',
  `supplier` varchar(50) CHARACTER SET utf8 COLLATE utf8_czech_ci DEFAULT NULL,
  `printed` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `intake_label_queue`
--
ALTER TABLE `intake_label_queue`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `intake_label_queue`
--
ALTER TABLE `intake_label_queue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
