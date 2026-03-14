-- phpMyAdmin SQL Dump
-- version 4.9.7
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Jan 16, 2026 at 06:19 PM
-- Server version: 10.3.32-MariaDB
-- PHP Version: 7.4.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
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
-- Table structure for table `disassembled_kits`
--

DROP TABLE IF EXISTS `disassembled_kits`;
CREATE TABLE `disassembled_kits` (
  `id` int(11) NOT NULL,
  `user` varchar(50) CHARACTER SET utf8 COLLATE utf8_czech_ci DEFAULT NULL,
  `barcode` varchar(50) CHARACTER SET utf8 COLLATE utf8_czech_ci DEFAULT NULL,
  `missing_barcode` varchar(50) CHARACTER SET utf8 COLLATE utf8_czech_ci DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `order_number` varchar(50) DEFAULT NULL,
  `timestamp` datetime DEFAULT current_timestamp(),
  `position` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Truncate table before insert `disassembled_kits`
--

TRUNCATE TABLE `disassembled_kits`;
--
-- Dumping data for table `disassembled_kits`
--

INSERT INTO `disassembled_kits` (`id`, `user`, `barcode`, `missing_barcode`, `quantity`, `order_number`, `timestamp`, `position`) VALUES
(6, 'FilipH', 'BE00073', 'rear fender', 1, '', '2025-11-12 08:12:25', 2),
(7, 'FilipH', 'HO00095', 'HO00103', 1, 'R191', '2025-11-12 08:13:08', 3),
(10, 'FilipH', 'KA00174', 'KA00138', 1, 'R191', '2025-11-12 08:17:49', 6),
(14, 'FilipH', 'GG00045', 'GG00055', 1, 'NOR195', '2025-11-12 08:23:22', 9),
(33, 'Filip Horvát ', 'GG00022', 'GG00207', 1, 'P97', '2025-11-27 08:57:01', 0),
(35, 'Filip Horvát ', 'KT00666', 'KT00654', 1, 'R194', '2025-11-27 08:57:36', 0),
(36, 'Filip Horvát ', 'KA00449', 'KA00266', 1, 'R195', '2025-12-04 08:56:20', 0),
(37, 'Filip Horvát ', 'KT00664', 'KT00679', 1, 'R195', '2025-12-04 08:58:01', 0),
(38, 'Filip Horvát ', 'KT00664', 'KT00679', 1, 'R195', '2025-12-04 08:58:14', 0),
(39, 'Filip Horvát ', 'KT00680', 'KT00607', 1, 'R195', '2025-12-04 08:58:23', 0),
(40, 'Filip Horvát ', 'HQ00249', 'HQ00543', 1, 'R195', '2025-12-05 12:37:18', 0),
(41, 'Filip Horvát ', 'HQ00249', 'R-PPHSQBLH024', 1, '', '2025-12-05 12:38:33', 0),
(42, 'Filip Horvát ', 'KT00558', 'KT00249', 1, 'R195', '2025-12-08 11:55:37', 0),
(43, 'Filip Horvát ', 'HQ00382', 'HQ00541', 1, 'R195', '2025-12-08 13:03:21', 0),
(45, 'Filip Horvát ', 'KT00904', 'KT00233', 1, 'P97/98', '2025-12-08 14:50:59', 0),
(46, 'Filip Horvát ', 'KT00904', 'KT00233', 1, 'P97/98', '2025-12-08 14:51:36', 0),
(47, 'Matej Scholtz ', 'YA00034', 'YA00775', 1, 'U166', '2025-12-09 12:21:41', 0),
(48, 'Filip Horvát ', 'HQ00383', 'HQ00369', 1, 'R195', '2025-12-10 07:32:50', 0),
(49, 'Filip Horvát ', 'HQ00382', 'HQ00541', 1, 'R195', '2025-12-10 07:38:35', 0),
(50, 'Filip Horvát ', 'HQ00548', 'HQ00543', 1, 'R195', '2025-12-10 07:56:30', 0),
(52, 'Filip Horvát ', 'HQ00385', 'HQ00359', 1, 'R195', '2025-12-15 12:10:28', 0),
(53, 'Filip Horvát ', 'KT00665', 'KT00618', 1, 'R195', '2025-12-16 13:49:59', 0),
(55, 'Filip Horvát ', 'KT00665', 'KT00611', 1, 'R195', '2025-12-17 13:50:58', 0),
(56, 'Filip Horvát ', 'KT00665', 'KT00619', 1, 'R195', '2025-12-17 13:51:10', 0),
(63, 'Matej Scholtz ', 'BE00072', 'BE00089', 1, 'R195', '2025-12-17 15:30:14', 0),
(64, 'Matej Scholtz ', 'HQ00385', 'R-PPHSQGR0024', 1, '', '2025-12-23 14:18:59', 0),
(65, 'Filip Horvát ', 'HQ00393', 'shrouds', 1, '', '2026-01-07 15:08:37', 0),
(66, 'Matej Scholtz ', 'YA00075', 'YA00516', 1, 'R196', '2026-01-08 08:02:53', 0),
(67, 'Matej Scholtz ', 'KT00933', 'KT00305', 1, 'P99', '2026-01-08 15:24:52', 0),
(69, 'Filip Horvát ', 'KT00665', 'KT00611', 1, 'R196', '2026-01-08 16:40:52', 0),
(70, 'Filip Horvát ', 'KT00665', 'KT00618', 1, 'R196', '2026-01-08 16:41:32', 0),
(71, 'Filip Horvát ', 'SU00092', 'SU00115', 1, 'P99', '2026-01-09 10:56:56', 0),
(72, 'Matej Scholtz ', 'KT00933', 'KT00305', 1, 'R195', '2026-01-10 10:33:25', 0),
(73, 'Filip Horvát ', 'KT00922', 'KT00243', 1, 'R196', '2026-01-13 12:00:10', 0),
(74, 'Filip Horvát ', 'HQ00382', 'HQ00360', 1, 'R196', '2026-01-13 15:31:21', 0),
(75, 'Matej Scholtz ', 'KT00927', 'KT00305', 1, 'R195', '2026-01-13 15:37:19', 0),
(76, 'Matej Scholtz ', 'KT00933', 'KT00185', 1, 'R195', '2026-01-14 12:02:46', 0),
(77, 'Matej Scholtz ', 'SU00311', '8559300002 rear fender', 1, 'P', '2026-01-14 14:20:22', 0),
(79, 'Matej Scholtz ', 'HQ00379', 'HQ00347', 1, '', '2026-01-15 07:21:26', 0),
(80, 'Matej Scholtz ', 'YA00137', 'YA00242', 1, '', '2026-01-15 12:25:28', 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `disassembled_kits`
--
ALTER TABLE `disassembled_kits`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `disassembled_kits`
--
ALTER TABLE `disassembled_kits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=81;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
