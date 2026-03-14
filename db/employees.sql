-- phpMyAdmin SQL Dump
-- version 4.9.7
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Mar 14, 2026 at 08:43 AM
-- Server version: 10.3.32-MariaDB
-- PHP Version: 7.4.30

SET FOREIGN_KEY_CHECKS=0;
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
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(15) NOT NULL,
  `firstname` varchar(50) CHARACTER SET utf8 COLLATE utf8_slovak_ci NOT NULL,
  `lastname` varchar(50) CHARACTER SET utf8 COLLATE utf8_slovak_ci NOT NULL,
  `address` text CHARACTER SET utf8 COLLATE utf8_slovak_ci NOT NULL,
  `birthdate` date NOT NULL,
  `contact_info` varchar(100) NOT NULL,
  `gender` varchar(10) NOT NULL,
  `position_id` int(11) NOT NULL,
  `schedule_id` int(11) NOT NULL,
  `photo` varchar(200) NOT NULL,
  `created_on` date NOT NULL,
  `online_status` tinyint(1) NOT NULL DEFAULT 2,
  `active` varchar(10) NOT NULL DEFAULT 'Active',
  `personal` varchar(1) NOT NULL DEFAULT 'X',
  `username` varchar(100) DEFAULT NULL,
  `permission` smallint(3) NOT NULL,
  `password` varchar(255) NOT NULL DEFAULT '*987EB5FD5561D486A510E32B59BEE3775EC4ED9E',
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Truncate table before insert `employees`
--

TRUNCATE TABLE `employees`;
--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `employee_id`, `firstname`, `lastname`, `address`, `birthdate`, `contact_info`, `gender`, `position_id`, `schedule_id`, `photo`, `created_on`, `online_status`, `active`, `personal`, `username`, `permission`, `password`) VALUES
(1, '2024-5353', 'Martin', 'Kotlárik', 'Borčice 275, 01853, Pošta Bolešov', '1969-02-22', '+421-905-713-553', 'Male', 7, 1, '2024-5353.png', '2019-03-01', 2, 'Active', 'C', 'zbrdlowski', 900, '*21138FE06E81697292C8FA56D8268CAF64819AAB'),
(2, '2020-2677', 'Andrej', 'Báaž  ', 'Partizánska 9\r\n91451 Trenčianske Teplice', '1987-06-18', '+421949704674', 'Male', 2, 1, '2020-2677.png', '2024-03-01', 2, 'Active', 'C', 'Andrej', 1, '*A5265CAA918CE68322B08523788880F5C979F8D1'),
(3, '2020-3438', 'Miloš', 'Sekerák', 'Levanduľová 39Kolačín', '1992-01-29', '+421915554060', 'Male', 3, 1, '2020-3438.png', '2018-03-01', 2, 'Active', 'C', 'milos', 500, '*987EB5FD5561D486A510E32B59BEE3775EC4ED9E'),
(4, '2024-2358', 'Vladimír', 'Kútny  ', 'Prejta 99', '1985-10-02', '09468029840', 'Male', 5, 1, '2024-2358.png', '2018-03-01', 2, 'Active', 'C', 'Kutas', 1, '*DD10C2A7BD35202546C909581732374E0670BF28'),
(5, '2024-2054', 'Dana', 'Pekarovičová', 'A. Hlinku 18/34\r\n92101 Piešťany', '1986-04-28', '+421903987654', 'Female', 3, 1, '2024-2054.png', '2024-02-01', 2, 'Active', 'C', 'dana', 500, '*987EB5FD5561D486A510E32B59BEE3775EC4ED9E'),
(6, '2020-3002', 'Michaela', 'Bulejková ', 'Drietoma 123/456911 09 Trenčín', '1989-09-15', '+421915069991', 'Female', 1, 1, '2020-3002.png', '2023-04-01', 2, 'Active', 'C', 'Miska', 1, '*D5F5BB33F0AA838435CB8B5A645DD24B77D609D5'),
(7, '2020-5819', 'Filip', 'Olbricht', 'Malozáblatská 760/32\r\n911 06 Trenčín', '1987-05-05', '+4219468029840', 'Male', 2, 1, '2020-5819.png', '2024-03-01', 2, 'Active', 'C', 'Detkil', 1, '*7BAB114696F5ED82CFB454FD73E048DAE016FF78'),
(8, '2024-4003', 'Andrej', 'Tomáš', 'Zárečie 141 Považská Bystrica', '1994-09-15', '+421 944515328', 'Male', 2, 1, 'andrej-tomas.png', '2024-03-01', 2, 'Inactive', 'C', 'user_2024-4003', 1, '*987EB5FD5561D486A510E32B59BEE3775EC4ED9E'),
(9, '2024-489', 'Katarína', 'Olbrichtová ', 'Malozáblatská 760/32\r\n911 06 Trenčín', '1992-01-30', '+421905700779', 'Female', 2, 4, '2024-489.png', '2024-03-01', 2, 'Active', 'C', 'Kika', 1, '*9A38B02B6198ED274E98383ACF562FF72CE74996'),
(10, '2024-5068', 'Katarína', 'Filimpocherová ', 'adresa', '1990-01-01', '+421905123456', 'Male', 2, 1, '2024-5068.png', '2024-03-01', 2, 'Active', 'C', 'KatkF', 1, '*4086C8CC68DBFB68D4A5097DAEBF7405607418EC'),
(11, '2024-9586', 'Viktória', 'Škultétyová ', 'Velke Hoste 151\r\nBanovce nad Bebravou', '2000-05-05', '+421948383229', 'Female', 2, 1, 'viky-skultetyova.png', '2024-03-01', 2, 'Active', 'C', 'Viky', 1, '*AB2213B7CC68D398BA66302734C4849D36B80C9D'),
(12, '2024-2942', 'Jaroslav', 'Novák ', 'Novomeského 2667/4\r\n91101 Trenčín', '1977-03-09', '+421910432220', 'Male', 4, 1, '2024-2942.png', '2024-03-01', 2, 'Active', 'C', 'Jarino', 1, '*FB22537BD69457646ECB60929187632CADF75D8D'),
(13, '2024-437', 'Peter', 'Cagala', 'Dolná Súča 395', '1987-02-18', '+421903581851', 'Male', 4, 1, '2024-437.png', '2024-03-01', 2, 'Active', 'C', 'user_2024-437', 1, '*987EB5FD5561D486A510E32B59BEE3775EC4ED9E'),
(14, '2024-786', 'Dorottya', 'Filiačová', 'Cementarska ulica 161/10', '1999-09-07', '+421944210691', 'Female', 4, 1, '2024-786.png', '2024-03-01', 2, 'Inactive', 'C', 'user_2024-786', 1, '*987EB5FD5561D486A510E32B59BEE3775EC4ED9E'),
(15, '2024-4971', 'Lukáš', 'Samaš', 'adresa', '1990-01-01', '+421905123456', 'Male', 4, 1, 'lukas-samas.png', '2024-03-01', 2, 'Active', 'C', 'user_2024-786', 1, '*987EB5FD5561D486A510E32B59BEE3775EC4ED9E'),
(16, '2024-2382', 'Patrik', 'Veres ', 'Štúrova 1150/4Piešťany', '1993-05-30', '+421911098197', 'Male', 6, 1, '2024-2382.png', '2024-03-01', 2, 'Active', 'C', 'Pato', 300, '*14167FBC1A3A06EB50C931C044298135E3EAC88D'),
(17, '2024-4065', 'Filip', 'Horvát ', 'Sladová 151/8\r\nMoravany Nad Váhom', '1992-03-25', '+421948647640', 'Male', 6, 1, '2024-4065.png', '2024-03-01', 2, 'Active', 'C', 'FilipH', 1, '*44DC12A67EA8700338E4EEEE612C1D66ACEA4A2C'),
(18, '2024-3659', 'Zachary', 'Clayton  ', 'adresa', '1989-08-04', '+421905123456', 'Male', 5, 1, '2024-3659.png', '2024-03-01', 2, 'Active', 'C', 'Zac', 1, '*37CCC13485DE2356B259238B9D1E3E4DC3B2EB70'),
(19, '2024-8039', 'Lucia', 'Kútna ', 'adresa', '1984-01-02', '+421915549185', 'Female', 8, 1, 'lucia-kutna.png', '2024-03-01', 2, 'Active', 'C', 'Lucia', 1, '*8D4A4453396B0D22BCC248A60035F55906BBE10D'),
(20, '2024-2800', 'Veronika', 'Žáková ', 'Družstevná 242/2\r\nPrejta', '2002-08-22', '+421902333187', 'Female', 2, 1, 'veronika-zakova.png', '2024-03-01', 2, 'Active', 'C', 'Veron', 1, '*6DA118D3BB0B1BA0E022622427207CA25F38958D'),
(21, '2024-9789', 'Mark', 'Roberts ', 'Hamre 456, Trenčianska Turná', '1969-12-28', '+421903111222', 'Male', 3, 1, 'mark-roberts.png', '2024-03-20', 2, 'Inactive', 'X', 'Mark', 300, '*0BEEB0F1E0E4B541A11E5124F3E7F6D55626DF20'),
(22, '2024-8886', 'Simona', 'Kopriva', 'NIžná Slaná\r\nP.J. Šafárika 260/123 Rožňava', '1998-03-02', '+421903047611', 'Female', 4, 1, 'simona-kopriva.png', '2024-04-12', 2, 'Inactive', 'C', 'user_2024-8886', 1, '*987EB5FD5561D486A510E32B59BEE3775EC4ED9E'),
(23, '2024-3734', 'Tomáš', 'Trunek', 'Soblahov 476\r\nTrenčín', '2003-06-30', '+421907231138', 'Male', 4, 1, 'tomas-trunek.png', '2024-04-17', 2, 'Active', 'C', 'user_2024-3734', 1, '*987EB5FD5561D486A510E32B59BEE3775EC4ED9E'),
(24, '2024-9170', 'Matej', 'Scholtz ', 'Mateja Bela 2465/38\r\n91101 Trenčín', '2004-01-30', '+421919410646', 'Male', 6, 1, 'matej-scholtz.png', '2024-05-14', 2, 'Active', 'C', 'Matej', 1, '*AD17C42D16E398DEE28AD30EC8DE0A3299CB8D54'),
(25, '2024-6547', 'Matúš', 'Jendrol', '28 Októbra 1170/15,\r\n911 01 Trenčín', '1997-08-02', '', 'Male', 4, 1, 'matus-jendrol.png', '2024-06-19', 2, 'Active', 'C', 'user_2024-6547', 1, '*987EB5FD5561D486A510E32B59BEE3775EC4ED9E'),
(26, '2024-1558', 'Radovan', 'Žilinský ', 'Kožušnícka 917/17. 911 05 Trenčín\r\nZámostie', '1984-01-30', '+421904920298', 'Male', 5, 1, 'radovan-zilinsky.png', '2024-07-10', 2, 'Active', 'C', 'Rado', 1, '*5A551C8C2EF2A873F3191456B37F65BA24D7C195'),
(27, '2025-9204', 'Soňa', 'Popov', 'Adresa', '1996-06-23', '+421 123 456', 'Female', 1, 1, 'sona-popov.png', '2025-01-01', 2, 'Active', 'C', 'user_2025-9204', 1, '*987EB5FD5561D486A510E32B59BEE3775EC4ED9E'),
(28, '2025-7861', 'Juraj', 'Pecko', 'Pod Mostom v Púchove ', '1986-09-19', '+421 655 930', 'Male', 5, 1, 'juraj-pecko.png', '2025-02-03', 2, 'Inactive', 'C', 'user_2025-7861', 1, '*987EB5FD5561D486A510E32B59BEE3775EC4ED9E'),
(29, '2025-2686', 'Veronika', 'Guzoňová ', '', '1991-02-16', '', 'Female', 2, 1, 'veronika-guzonova.png', '2025-02-03', 2, 'Active', 'C', 'Veronika', 1, '*D19708F414BA6E4566F54263EFB068090080424F'),
(30, '2025-7140', 'Alex', 'Predanocy  ', 'Dresa 123', '2003-02-28', '', 'Male', 4, 1, 'alex-predanocy.png', '2025-02-03', 2, 'Active', 'C', 'Alex', 1, '*1E7B70A21EEB8D0CFE695AB57B699560ED239990'),
(31, '2025-8957', 'Marianna', 'Vrábelová ', 'adresa', '1997-12-06', '+421944426895', 'Female', 2, 1, 'marianna-vrabelova.png', '2025-05-30', 2, 'Active', 'C', 'Marianna', 1, '*DC806C98B93BF1C9C654F427BE878DB87A2B3240'),
(32, '2025-5241', 'Lukáš', 'Pikna ', 'Slnečná 341/18 \r\n956 22 Prašice', '1990-09-05', '+421915104188', 'Male', 2, 1, 'lukas-pikna.png', '2025-07-01', 2, 'Active', 'C', 'Lukas', 1, '*F9A7ED3A671A89BE160D39020BF86163FA27F81D'),
(33, '2025-2248', 'Lukáš', 'Balko', 'Nova Dubnica', '2006-05-18', '+421 123 456', 'Male', 4, 1, 'lukas-balko.png', '2025-08-04', 2, 'Active', 'C', 'user_2025-2248', 1, '*987EB5FD5561D486A510E32B59BEE3775EC4ED9E'),
(35, '2025-1498', 'Ivan', 'Sedlár ', 'Fraňa Madvu 1118/1, Nemšová', '2005-06-18', '+421 951 341 708', 'Male', 2, 1, 'ivan-sedlar.png', '2025-09-22', 2, 'Active', 'C', 'Ivan', 1, '*C5E790E989669ACCD38F10A48206A1199E03F514'),
(36, '2026-1255', 'Mária', 'Žáková ', 'Adresa', '2000-01-01', '+42 123 456 789', 'Female', 2, 1, 'maria-zakova.png', '2026-02-02', 2, 'Active', 'C', 'Mzakova', 1, '*DAFD22482A2B067D720F20FA60188C6BC5D89140'),
(37, '2026-9301', 'Dominika', 'Bieleschova ', 'Dortmeister Straße 147/15 Dortmund, Germany', '1997-02-17', '+421903987654', 'Female', 1, 1, 'dominika-bielesch.png', '2026-02-16', 2, 'Inactive', 'C', 'Dominika', 500, '*42DF94BBFE4537370FC3F2238B2790648462410B');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;
SET FOREIGN_KEY_CHECKS=1;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
