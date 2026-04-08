-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 18, 2025 at 03:54 PM
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
-- Database: `ecommerce_dev`
--

-- --------------------------------------------------------

--
-- Table structure for table `ec_cart`
--

CREATE TABLE `ec_cart` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `session_id` varchar(255) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `size` varchar(50) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ec_cart`
--

INSERT INTO `ec_cart` (`id`, `user_id`, `session_id`, `product_id`, `quantity`, `size`, `color`, `created_at`, `updated_at`) VALUES
(68, 45, NULL, 30, 2, NULL, NULL, '2025-09-17 19:40:06', '2025-09-17 19:40:51'),
(74, 42, NULL, 27, 3, NULL, NULL, '2025-09-18 19:10:06', '2025-09-18 19:11:07'),
(75, 42, NULL, 15, 2, NULL, NULL, '2025-09-18 19:10:26', '2025-09-18 19:11:11');

-- --------------------------------------------------------

--
-- Table structure for table `ec_categories`
--

CREATE TABLE `ec_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `show_on_homepage` tinyint(1) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ec_categories`
--

INSERT INTO `ec_categories` (`id`, `name`, `slug`, `description`, `icon`, `image`, `status`, `show_on_homepage`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 'Casual Dresses', 'casual', 'Comfortable everyday dresses', 'casual-icon.svg', NULL, 'active', 1, 1, '2025-07-30 16:41:50', '2025-07-30 16:41:50'),
(2, 'Formal Wear', 'formal', 'Elegant dresses for special occasions', 'formal-icon.svg', NULL, 'active', 1, 2, '2025-07-30 16:41:50', '2025-07-30 16:41:50'),
(3, 'Evening Dresses', 'evening', 'Glamorous evening wear', 'evening-icon.svg', NULL, 'active', 1, 3, '2025-07-30 16:41:50', '2025-07-30 16:41:50'),
(4, 'Summer Collection', 'summer', 'Light and breezy summer dresses', 'summer-icon.svg', NULL, 'active', 1, 4, '2025-07-30 16:41:50', '2025-07-30 16:41:50'),
(5, 'Winter Styles', 'winter', 'Warm and cozy winter dresses', 'winter-icon.svg', NULL, 'active', 1, 5, '2025-07-30 16:41:50', '2025-07-30 16:41:50'),
(10, 'cotton cloths', 'cotton-cloths', 'sdsdsdffggfg', '68a4f719a93c8_1755641625.svg', NULL, 'active', 1, 6, '2025-08-20 03:43:45', '2025-08-20 03:43:45');

-- --------------------------------------------------------

--
-- Table structure for table `ec_login_attempts`
--

CREATE TABLE `ec_login_attempts` (
  `id` int(11) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `fingerprint` varchar(64) DEFAULT NULL,
  `attempt_time` datetime NOT NULL,
  `user_agent` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ec_orders`
--

CREATE TABLE `ec_orders` (
  `id` int(11) NOT NULL,
  `pid` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `user_id` int(11) DEFAULT NULL,
  `order_number` varchar(50) NOT NULL,
  `razorpay_order_id` varchar(255) DEFAULT NULL,
  `razorpay_payment_id` varchar(255) DEFAULT NULL,
  `status` enum('pending','processing','shipped','delivered','cancelled','refunded','cancel_requested','cancel_confirmed','return_requested','return_confirmed','request_rejected') NOT NULL DEFAULT 'pending',
  `delivered_on` datetime DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `shipping_cost` decimal(10,2) DEFAULT 0.00,
  `tax_amount` decimal(10,2) DEFAULT 0.00,
  `discount_amount` decimal(10,2) DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_status` enum('pending','paid','failed','refunded') DEFAULT 'pending',
  `shipping_address` text DEFAULT NULL,
  `billing_address` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ec_orders`
--

INSERT INTO `ec_orders` (`id`, `pid`, `quantity`, `user_id`, `order_number`, `razorpay_order_id`, `razorpay_payment_id`, `status`, `delivered_on`, `total_amount`, `shipping_cost`, `tax_amount`, `discount_amount`, `payment_method`, `payment_status`, `shipping_address`, `billing_address`, `notes`, `created_at`, `updated_at`) VALUES
(1, 15, 2, 1, 'ORD1010', NULL, NULL, 'delivered', NULL, 2500.00, 100.00, 50.00, 200.00, 'Credit Card', 'paid', '123 Street, Downtown, Ahmedabad', '123 Street, Downtown, Ahmedabad', 'Fast delivery requested', '2025-08-14 18:50:22', '2025-08-24 22:56:33'),
(3, 15, 3, 1, 'ORD1008', NULL, NULL, 'delivered', NULL, 3000.00, 120.00, 60.00, 100.00, 'Net Banking', 'paid', '123 Street, Downtown, Ahmedabad', '123 Street, Downtown, Ahmedabad', '', '2025-08-19 18:50:22', '2025-08-24 22:54:48'),
(4, 4, 1, 3, 'ORD1007', NULL, NULL, 'cancelled', NULL, 800.00, 50.00, 20.00, 0.00, 'Cash on Delivery', 'failed', '789 Road, Old City, Delhi', '789 Road, Old City, Delhi', 'Cancelled by user', '2025-08-21 18:50:22', '2025-08-21 18:50:22'),
(5, 15, 2, 4, 'ORD1005', NULL, NULL, 'delivered', '2025-08-25 01:16:53', 1600.00, 90.00, 40.00, 150.00, 'Credit Card', 'refunded', '12 Block, New Colony, Surat', '12 Block, New Colony, Surat', 'Refund issued', '2025-08-22 18:50:22', '2025-08-25 01:16:53'),
(6, 6, 5, 2, 'ORD1006', NULL, NULL, 'refunded', NULL, 5000.00, 150.00, 100.00, 500.00, 'UPI', 'refunded', '456 Avenue, Uptown, Mumbai', '456 Avenue, Uptown, Mumbai', '', '2025-08-23 18:50:22', '2025-08-25 00:52:14'),
(11, 1, 3, 45, 'ORD-1001', NULL, NULL, 'delivered', '2025-08-25 01:03:28', 1500.00, 50.00, 75.00, 100.00, 'COD', 'refunded', '123 Main Street, Ahmedabad', '123 Main Street, Ahmedabad', 'First order - awaiting confirmation\n\nRequest cancel requested on 2025-08-11 14:23:51\nReason: ghjhgjghkhkjkjkgffgdg\n\nRequest cancel requested on 2025-08-11 15:12:21\nReason: hgdfhdghfgfhdg\n\nRequest return requested on 2025-09-18 03:59:13\nReason: yutyuytuyutyuyuttttttttttt\nImage: returns/45/return_11_1758148153.jpeg\n\nRequest return requested on 2025-09-18 03:59:31\nReason: yutyuytuyutyuyuttttttttttt\nImage: returns/45/return_11_1758148171.jpeg', '2025-08-09 15:55:11', '2025-09-18 04:06:45'),
(12, 2, 1, 45, 'ORD-1002', NULL, NULL, 'delivered', NULL, 2499.00, 80.00, 120.00, 0.00, 'Credit Card', 'paid', '45 Park Lane, Ahmedabad', '45 Park Lane, Ahmedabad', 'Gift order', '2025-08-09 15:55:11', '2025-08-24 05:03:27'),
(13, 3, 1, 45, 'ORD-1003', NULL, NULL, 'request_rejected', NULL, 999.00, 40.00, 50.00, 0.00, 'UPI', 'paid', '78 MG Road, Ahmedabad', '78 MG Road, Ahmedabad', 'Delivered successfully\n\nRequest return requested on 2025-08-11 13:59:40\nReason: cvfvgbgbghnhnhnh\nImage: returns/4/return_13_1754900980.jpg\n\nRequest return requested on 2025-08-11 14:21:35\nReason: sffghgjhkjlkl\nImage: returns/4/return_13_1754902295.jpg\n\nRequest return requested on 2025-08-11 14:22:02\nReason: gfgfgfghfgf\nImage: returns/4/return_13_1754902322.jpg\n\nRequest return requested on 2025-08-11 14:24:22\nReason: dgfgfgfdgdfgf\nImage: returns/4/return_13_1754902462.jpg\n\nRequest return requested on 2025-08-11 14:58:08\nReason: asdfghjkl\nImage: returns/4/return_13_1754904488.jpg\n\nRequest return requested on 2025-08-11 15:27:59\nReason: fgsvxfasffjbdvfgvdff vvvdjhvsfhfjh\nImage: returns/4/return_13_1754906279.jpg', '2025-08-09 15:55:11', '2025-08-21 15:58:17'),
(14, 1, 7, 45, 'ORD-1004', NULL, NULL, 'delivered', NULL, 7000.00, 50.00, 75.00, 100.00, 'COD', 'pending', '123 Main Street, Ahmedabad', '123 Main Street, Ahmedabad', 'First order - awaiting confirmation', '2025-08-15 15:55:11', '2025-08-24 18:32:25'),
(16, NULL, 1, 42, 'ORD202509172358103066', NULL, NULL, 'cancelled', NULL, 1091.13, 100.00, 151.19, 0.00, 'razorpay', 'pending', 'Ammar Chhipa\nD/3 Banas flat\nOpp. SLU College Ellis bridge\nAhmedabad, Gujarat 380006\nIndia', 'Ammar Chhipa\nD/3 Banas flat\nOpp. SLU College Ellis bridge\nAhmedabad, Gujarat 380006\nIndia\nPhone: +917016043900', '\n\nRequest cancel requested on 2025-09-18 02:28:16\nReason: dont want', '2025-09-17 23:58:10', '2025-09-18 02:29:35'),
(24, NULL, 1, 45, 'ORD202509181440289033', NULL, NULL, 'pending', NULL, 4348.00, 100.00, 648.00, 0.00, 'razorpay', 'pending', '{\"first_name\":\"Ammar\",\"last_name\":\"Chhipa\",\"email\":\"ammarchhipa786@gmail.com\",\"phone\":\"+917016043900\",\"address_line_1\":\"D\\/3 Banas flat\",\"address_line_2\":\"Opp. SLU College Ellis bridge\",\"city\":\"Ahmedabad\",\"state\":\"Gujarat\",\"postal_code\":\"380006\",\"country\":\"India\"}', '{\"first_name\":\"Ammar\",\"last_name\":\"Chhipa\",\"address_line_1\":\"D\\/3 Banas flat\",\"address_line_2\":\"Opp. SLU College Ellis bridge\",\"city\":\"Ahmedabad\",\"state\":\"Gujarat\",\"postal_code\":\"380006\",\"country\":\"India\",\"phone\":\"+917016043900\"}', 'fast', '2025-09-18 14:40:28', '2025-09-18 14:40:28'),
(25, NULL, 1, 45, 'ORD202509181451518513', 'order_RJ0spI95ilR7oC', NULL, 'pending', NULL, 4348.00, 100.00, 648.00, 0.00, 'razorpay', 'pending', '{\"first_name\":\"Ammar\",\"last_name\":\"Chhipa\",\"email\":\"ammarchhipa786@gmail.com\",\"phone\":\"+917016043900\",\"address_line_1\":\"D\\/3 Banas flat\",\"address_line_2\":\"Opp. SLU College Ellis bridge\",\"city\":\"Ahmedabad\",\"state\":\"Gujarat\",\"postal_code\":\"380006\",\"country\":\"India\"}', '{\"first_name\":\"Ammar\",\"last_name\":\"Chhipa\",\"address_line_1\":\"D\\/3 Banas flat\",\"address_line_2\":\"Opp. SLU College Ellis bridge\",\"city\":\"Ahmedabad\",\"state\":\"Gujarat\",\"postal_code\":\"380006\",\"country\":\"India\",\"phone\":\"+917016043900\"}', 'fast delivery', '2025-09-18 14:51:51', '2025-09-18 09:21:52'),
(26, NULL, 1, 45, 'ORD202509181455216997', 'order_RJ0wWu9XIJtLIH', NULL, 'pending', NULL, 4348.00, 100.00, 648.00, 0.00, 'razorpay', 'pending', '{\"first_name\":\"Ammar\",\"last_name\":\"Chhipa\",\"email\":\"ammarchhipa786@gmail.com\",\"phone\":\"+917016043900\",\"address_line_1\":\"D\\/3 Banas flat\",\"address_line_2\":\"Opp. SLU College Ellis bridge\",\"city\":\"Ahmedabad\",\"state\":\"Gujarat\",\"postal_code\":\"380006\",\"country\":\"India\"}', '{\"first_name\":\"Ammar\",\"last_name\":\"Chhipa\",\"address_line_1\":\"D\\/3 Banas flat\",\"address_line_2\":\"Opp. SLU College Ellis bridge\",\"city\":\"Ahmedabad\",\"state\":\"Gujarat\",\"postal_code\":\"380006\",\"country\":\"India\",\"phone\":\"+917016043900\"}', 'fast delivery', '2025-09-18 14:55:21', '2025-09-18 09:25:23'),
(39, NULL, 1, 42, 'ORD202509181605068984', 'order_RJ28ENI3viadFK', NULL, 'pending', NULL, 454.00, 100.00, 54.00, 0.00, 'razorpay', 'pending', '{\"first_name\":\"Ammar\",\"last_name\":\"Chhipa\",\"email\":\"ammarchhipa2003@gmail.com\",\"phone\":\"+917016043900\",\"address_line_1\":\"ELLISBRIDGE\",\"address_line_2\":\"D\\/3, BANAS FLAT\",\"city\":\"AHMEDABAD\",\"state\":\"Gujarat\",\"postal_code\":\"380006\",\"country\":\"India\"}', '{\"first_name\":\"Ammar\",\"last_name\":\"Chhipa\",\"address_line_1\":\"ELLISBRIDGE\",\"address_line_2\":\"D\\/3, BANAS FLAT\",\"city\":\"AHMEDABAD\",\"state\":\"Gujarat\",\"postal_code\":\"380006\",\"country\":\"India\",\"phone\":\"+917016043900\"}', 'fast', '2025-09-18 16:05:06', '2025-09-18 10:35:09'),
(40, NULL, 1, 42, 'ORD202509181610169232', 'order_RJ2DfT6U3lLpVn', NULL, 'cancelled', NULL, 4112.00, 100.00, 612.00, 0.00, 'razorpay', 'failed', '{\"first_name\":\"Ammar\",\"last_name\":\"Chhipa\",\"email\":\"ammarchhipa2003@gmail.com\",\"phone\":\"+917016043900\",\"address_line_1\":\"ELLISBRIDGE\",\"address_line_2\":\"D\\/3, BANAS FLAT\",\"city\":\"AHMEDABAD\",\"state\":\"Gujarat\",\"postal_code\":\"380006\",\"country\":\"India\"}', '{\"first_name\":\"Ammar\",\"last_name\":\"Chhipa\",\"address_line_1\":\"ELLISBRIDGE\",\"address_line_2\":\"D\\/3, BANAS FLAT\",\"city\":\"AHMEDABAD\",\"state\":\"Gujarat\",\"postal_code\":\"380006\",\"country\":\"India\",\"phone\":\"+917016043900\"}', 'super fast', '2025-09-18 16:10:16', '2025-09-18 16:10:58'),
(41, NULL, 1, 42, 'ORD202509181614117795', 'order_RJ2Ho7oFJIEsQY', 'pay_RJ2IrBgdWUByuK', 'processing', NULL, 4112.00, 100.00, 612.00, 0.00, 'razorpay', 'paid', '{\"first_name\":\"Ammar\",\"last_name\":\"Chhipa\",\"email\":\"ammarchhipa2003@gmail.com\",\"phone\":\"+917016043900\",\"address_line_1\":\"D\\/3 Banas flat\",\"address_line_2\":\"Opp. SLU College Ellis bridge\",\"city\":\"Ahmedabad\",\"state\":\"Gujarat\",\"postal_code\":\"380006\",\"country\":\"India\"}', '{\"first_name\":\"Ammar\",\"last_name\":\"Chhipa\",\"address_line_1\":\"D\\/3 Banas flat\",\"address_line_2\":\"Opp. SLU College Ellis bridge\",\"city\":\"Ahmedabad\",\"state\":\"Gujarat\",\"postal_code\":\"380006\",\"country\":\"India\",\"phone\":\"+917016043900\"}', 'fast', '2025-09-18 16:14:11', '2025-09-18 16:15:27'),
(42, NULL, 1, 42, 'ORD202509181814514180', 'order_RJ4LILNigHX9ee', NULL, 'pending', NULL, 3640.00, 100.00, 540.00, 0.00, 'razorpay', 'pending', '{\"first_name\":\"Ammar\",\"last_name\":\"Chhipa\",\"email\":\"ammarchhipa2003@gmail.com\",\"phone\":\"+917016043900\",\"address_line_1\":\"D\\/3 Banas flat\",\"address_line_2\":\"Opp. SLU College Ellis bridge\",\"city\":\"Ahmedabad\",\"state\":\"Gujarat\",\"postal_code\":\"380006\",\"country\":\"India\"}', '{\"first_name\":\"Ammar\",\"last_name\":\"Chhipa\",\"address_line_1\":\"D\\/3 Banas flat\",\"address_line_2\":\"Opp. SLU College Ellis bridge\",\"city\":\"Ahmedabad\",\"state\":\"Gujarat\",\"postal_code\":\"380006\",\"country\":\"India\",\"phone\":\"+917016043900\"}', 'Super fast', '2025-09-18 18:14:51', '2025-09-18 12:44:54'),
(43, NULL, 1, 42, 'ORD202509181835012445', 'order_RJ4gaYYzQXbane', 'pay_RJ4jdScKxdHv9h', 'processing', NULL, 3640.00, 100.00, 540.00, 0.00, 'razorpay', 'paid', '{\"first_name\":\"Ammar\",\"last_name\":\"Chhipa\",\"email\":\"ammarchhipa2003@gmail.com\",\"phone\":\"+917016043900\",\"address_line_1\":\"D\\/3 Banas flat\",\"address_line_2\":\"Opp. SLU College Ellis bridge\",\"city\":\"Ahmedabad\",\"state\":\"Gujarat\",\"postal_code\":\"380006\",\"country\":\"India\"}', '{\"first_name\":\"Ammar\",\"last_name\":\"Chhipa\",\"address_line_1\":\"D\\/3 Banas flat\",\"address_line_2\":\"Opp. SLU College Ellis bridge\",\"city\":\"Ahmedabad\",\"state\":\"Gujarat\",\"postal_code\":\"380006\",\"country\":\"India\",\"phone\":\"+917016043900\"}', 'super fast', '2025-09-18 18:35:01', '2025-09-18 18:38:11');

-- --------------------------------------------------------

--
-- Table structure for table `ec_order_items`
--

CREATE TABLE `ec_order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `color` varchar(50) DEFAULT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ec_order_items`
--

INSERT INTO `ec_order_items` (`id`, `order_id`, `product_id`, `quantity`, `color`, `unit_price`, `created_at`) VALUES
(1, 11, 1, 3, NULL, 516.67, '2025-09-18 01:42:02'),
(2, 12, 2, 1, NULL, 2419.00, '2025-09-18 01:42:02'),
(3, 13, 3, 1, NULL, 959.00, '2025-09-18 01:42:02'),
(4, 14, 1, 1, NULL, 6950.00, '2025-09-18 01:42:02'),
(5, 5, 15, 4, NULL, 415.00, '2025-09-18 01:42:02'),
(6, 6, 6, 2, NULL, 2500.00, '2025-09-18 01:42:02'),
(7, 4, 4, 3, NULL, 276.67, '2025-09-18 01:42:02'),
(8, 3, 15, 1, NULL, 3040.00, '2025-09-18 01:42:02'),
(9, 11, 15, 1, NULL, 2400.00, '2025-09-18 01:42:02'),
(11, 24, 30, 2, NULL, 1800.00, '2025-09-18 09:10:28'),
(12, 25, 30, 2, NULL, 1800.00, '2025-09-18 09:21:51'),
(13, 26, 30, 2, NULL, 1800.00, '2025-09-18 09:25:21'),
(26, 39, 2, 2, NULL, 150.00, '2025-09-18 10:35:06'),
(27, 40, 30, 1, NULL, 1800.00, '2025-09-18 10:40:16'),
(28, 40, 31, 1, NULL, 1600.00, '2025-09-18 10:40:16'),
(29, 41, 30, 1, NULL, 1800.00, '2025-09-18 10:44:11'),
(30, 41, 31, 1, NULL, 1600.00, '2025-09-18 10:44:11'),
(31, 42, 25, 1, NULL, 1200.00, '2025-09-18 12:44:51'),
(32, 42, 30, 1, NULL, 1800.00, '2025-09-18 12:44:51'),
(33, 43, 25, 1, NULL, 1200.00, '2025-09-18 13:05:01'),
(34, 43, 30, 1, NULL, 1800.00, '2025-09-18 13:05:01');

-- --------------------------------------------------------

--
-- Table structure for table `ec_products`
--

CREATE TABLE `ec_products` (
  `id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `short_description` varchar(500) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `sale_price` decimal(10,2) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `gallery` text DEFAULT NULL,
  `available_sizes` varchar(255) DEFAULT NULL,
  `available_colors` varchar(255) DEFAULT NULL,
  `stock_quantity` int(11) DEFAULT 0,
  `sku` varchar(100) DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive','draft') DEFAULT 'active',
  `featured` tinyint(1) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ec_products`
--

INSERT INTO `ec_products` (`id`, `product_id`, `name`, `slug`, `description`, `short_description`, `price`, `sale_price`, `category_id`, `image`, `gallery`, `available_sizes`, `available_colors`, `stock_quantity`, `sku`, `tags`, `status`, `featured`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 1, 'Elegant Floral Midi Dress', 'elegant-floral-midi-dress', 'Beautiful midi dress with floral print perfect for any occasion.', 'Elegant floral print midi dress', 89.99, NULL, 4, 'dress1.jpg', NULL, 'XS', 'Pink', 15, '', '', 'active', 1, 1, '2025-07-30 16:41:50', '2025-08-19 19:02:18'),
(2, 1, 'Classic Black Evening Gown', 'classic-black-evening-gown', 'Timeless black evening gown for special occasions.', 'Classic black evening gown', 199.99, 150.00, 2, '68a4f3f169b1f_1755640817.jpg', '68a4f3f16a12c_1755640817.jpg,68a4f3f16a853_1755640817.png', 'XL', 'Black', 10, '', '', 'active', 1, 2, '2025-07-30 16:41:50', '2025-08-20 03:30:17'),
(3, 1, 'Summer Maxi Dress', 'summer-maxi-dress', 'Light and airy maxi dress perfect for summer days.', 'Flowing summer maxi dress', 69.99, 49.99, 1, 'dress3.jpg', NULL, 'S', 'Yellow', 30, NULL, NULL, 'active', 1, 3, '2025-07-30 16:41:50', '2025-08-19 19:02:46'),
(4, 1, 'Professional Blazer Dress', 'professional-blazer-dress', 'Smart blazer dress perfect for office wear.', 'Professional blazer dress', 129.99, NULL, 5, 'dress4.jpg', '', 'XS,S,M,L,XL,XXL', 'Navy', 20, '', '', 'active', 1, 4, '2025-07-30 16:41:50', '2025-08-19 19:02:32'),
(6, 2, 'abc', 'professional-dress', 'Smart blazer dress perfect for office wear.', 'Professional blazer dress', 129.99, NULL, 1, 'dress1.jpg', 'dress1.jpg,dress3.jpg', 'XS', 'Gray', 20, NULL, NULL, 'active', 0, 4, '2025-07-30 16:41:50', '2025-08-19 19:01:28'),
(15, 2, 'cotton suits', 'cotton-suits', 'hi there is my new \r\nitem', 'hello world', 1900.00, NULL, 3, '68a37d633cc4a_1755544931.jpg', '68a37d633d1da_1755544931.jpg,68a37d633d8c2_1755544931.jpg', '', 'Navy', 3, 'best-cotton-suits', 'elegent,summer,casual', 'active', 1, 0, '2025-08-19 00:52:11', '2025-08-20 03:26:40'),
(24, 3, 'Cotton Unstitched Suit', 'cotton-unstitched-suit', 'Cotton unstitched suits are soft and ideal for summer, providing comfort in hot climates. They are easy to maintain and great for casual or office wear.\r\nTags: Cotton, Daily Wear, Summer, Comfortable, Lightweight, Breathable, Office Wear', 'Light, breathable fabric perfect for daily wear.', 1000.00, NULL, 10, '68c95ef97416f_1758027513.jpeg', '', NULL, 'red', 10, 'COTTON001', 'Cotton, Daily Wear, Summer, Comfortable, Lightweight, Breathable, Office Wear', 'active', 1, 0, '2025-09-16 18:28:33', '2025-09-16 18:28:33'),
(25, 4, 'Silk Unstitched Suit', 'silk-unstitched-suit', 'Silk unstitched suits offer a rich, glossy finish, giving an elegant look for special occasions. They drape beautifully and are preferred for festive or formal events.', 'Luxurious fabric suitable for weddings and parties.', 1200.00, NULL, 3, '68c979ede10f8_1758034413.jpg', '68c979ede2639_1758034413.jpg,68c979ede39aa_1758034413.jpg', NULL, 'yellow', 14, 'SILKUN001', 'Silk, Festive, Wedding, Party Wear, Luxury, Glossy, Formal', 'active', 0, 0, '2025-09-16 20:23:33', '2025-09-18 13:08:11'),
(26, 3, 'Georgette Unstitched Suit', 'georgette-unstitched-suit', 'Georgette unstitched suits are flowy and easy to carry, perfect for semi-formal occasions. They add grace with good drape and subtle shimmer.', 'Lightweight with a slightly textured surface, ideal for parties.', 900.00, NULL, 10, '68c97a98cf9c4_1758034584.jpg', '68c97a98d00d6_1758034584.jpg,68c97a98d0917_1758034584.jpg', NULL, 'blue', 7, 'GEORGE001', 'Georgette, Party Wear, Semi-formal, Lightweight, Flowing, Textured', 'active', 0, 0, '2025-09-16 20:26:24', '2025-09-16 20:26:24'),
(27, 3, 'Chiffon Unstitched Suit', 'chiffon-unstitched-suit', 'Chiffon unstitched suits are airy and give a light, graceful appearance. They’re often used for festive or evening wear with embroidery or embellishments.', 'Sheer and soft fabric for elegant gatherings.', 1500.00, 1200.00, 2, '68c9814fe5279_1758036303.jpg', '68c9814fe57b0_1758036303.jpeg,68c9814fe5bfb_1758036303.jpg', NULL, 'light green', 5, 'CHIFFO001', 'Chiffon, Sheer, Lightweight, Festive, Elegant, Evening Wear, Embellished', 'active', 1, 0, '2025-09-16 20:55:03', '2025-09-16 20:55:03'),
(28, 3, 'Linen Unstitched Suit', 'linen-unstitched-suit', 'Linen unstitched suits are extremely breathable and perfect for summer. They offer a simple, elegant look while keeping you cool and comfortable all day.', 'Natural fabric, breathable and good for hot climates.', 1400.00, NULL, 2, '68c99d7d5fe80_1758043517.jpg', '68c99d7d612f2_1758043517.jpeg,68c99d7d623e9_1758043517.jpg', NULL, 'green', 12, 'LINENU001', 'Linen, Summer, Breathable, Natural, Comfortable, Simple, Hot Climate', 'active', 1, 0, '2025-09-16 22:55:17', '2025-09-16 22:55:17'),
(29, NULL, 'Crepe Unstitched Suit', 'crepe-unstitched-suit', 'Crepe unstitched suits have a crinkled texture and good fall, suitable for both formal and casual occasions. They offer a refined look with easy maintenance.', 'Slightly textured, drapes well for casual or formal use.', 1700.00, 1500.00, 2, '68c99e2e83baa_1758043694.jpg', '68c99e2e8421f_1758043694.jpeg,68c99e2e849ce_1758043694.jpg', NULL, 'cyan', 13, 'CREPEU001', 'Crepe, Textured, Formal, Casual, Good Drape, Easy Maintenance', 'active', 1, 0, '2025-09-16 22:58:14', '2025-09-16 22:58:14'),
(30, 4, 'Cotton Silk Unstitched Suit', 'cotton-silk-unstitched-suit', 'Cotton Silk unstitched suits combine the breathability of cotton with the luster of silk. Ideal for festive and semi-formal wear, they balance comfort and elegance.', 'Blend of comfort and sheen, perfect for semi-formal occasions.', 2000.00, 1800.00, 10, '68c99ea2b1c63_1758043810.jpg', '68c99ea2b2d22_1758043810.jpg,68c99ea2b3531_1758043810.jpg', NULL, 'pink', 13, 'COTTON002', 'Cotton Silk, Semi-formal, Festive, Comfortable, Glossy, Blend Fabric', 'active', 1, 0, '2025-09-16 23:00:10', '2025-09-18 13:08:11'),
(31, 1, 'Velvet Unstitched Suit', 'velvet-unstitched-suit', 'Velvet unstitched suits provide a royal look with a soft, thick texture, perfect for winter occasions and weddings. They are warm and elegant, often featuring embroidery.', 'Rich, heavy fabric best for winters and weddings.', 1600.00, NULL, 5, '68c99f2fd630b_1758043951.jpeg', '68c99f2fd6f7e_1758043951.jpg,68c99f2fd790c_1758043951.jpeg', NULL, 'light pink', 9, 'VELVET001', 'Velvet, Winter, Weddings, Rich, Thick, Royal, Embroidered', 'active', 1, 0, '2025-09-16 23:02:31', '2025-09-18 10:45:27');

-- --------------------------------------------------------

--
-- Table structure for table `ec_promotions`
--

CREATE TABLE `ec_promotions` (
  `id` int(11) NOT NULL,
  `type` enum('marquee','banner') NOT NULL,
  `content` text DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ec_promotions`
--

INSERT INTO `ec_promotions` (`id`, `type`, `content`, `image_url`, `link_url`, `is_active`, `created_at`) VALUES
(25, 'banner', 'dddddddddddddddddddddddddddddddddddddd', 'promo_1757882958_bda92dc2.jpg', '/ecommerce-project/products/product_list.php?sale=1', 1, '2025-09-14 20:49:18'),
(26, 'marquee', 'Get upto 60% off on featured products', NULL, '/ecommerce-project/products/product_list.php?sale=1', 1, '2025-09-17 14:07:05');

-- --------------------------------------------------------

--
-- Table structure for table `ec_testimonials`
--

CREATE TABLE `ec_testimonials` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `customer_name` varchar(100) NOT NULL,
  `review` text NOT NULL,
  `rating` int(1) NOT NULL DEFAULT 5,
  `helpful_count` int(11) DEFAULT 0,
  `verified_purchase` tinyint(1) DEFAULT 0,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `sort_order` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ec_testimonials`
--

INSERT INTO `ec_testimonials` (`id`, `product_id`, `variant_id`, `user_id`, `title`, `customer_name`, `review`, `rating`, `helpful_count`, `verified_purchase`, `status`, `sort_order`, `created_at`) VALUES
(4, 2, 3, 4, 'Great value for money', 'Jessica Thompson', 'Good quality dress at a reasonable price. The fabric is nice and comfortable to wear. Perfect for casual outings or office wear.', 4, 6, 1, 'approved', 4, '2024-12-12 11:15:00'),
(5, 2, 4, 5, 'Beautiful design', 'Anna Wilson', 'I love the unique design of this dress. It\'s versatile and can be dressed up or down. The only minor issue is that it wrinkles easily, but overall very satisfied.', 4, 9, 0, 'approved', 5, '2024-12-11 13:30:00'),
(6, 3, 5, 6, 'Stunning dress!', 'Rachel Davis', 'This dress is absolutely stunning! The quality is exceptional and it fits perfectly. I wore it to a dinner party and received numerous compliments. Worth every penny!', 5, 20, 1, 'approved', 6, '2024-12-10 09:20:00'),
(7, 3, 5, 7, 'Good but could be better', 'Lisa Brown', 'The dress is nice but the fabric feels a bit thin for the price. The design is beautiful though and it fits well. Would consider buying again if there was a sale.', 3, 3, 1, 'approved', 7, '2024-12-09 15:45:00'),
(8, 1, 1, 8, 'Absolutely love it!', 'Michelle Lee', 'Just received my order and I\'m so happy with this purchase! The dress is even more beautiful in person. The fabric quality is excellent and the fit is perfect. Can\'t wait to wear it!', 5, 0, 1, 'pending', 8, '2024-12-16 12:00:00'),
(9, 2, 3, 9, 'Comfortable and stylish', 'Jennifer Adams', 'This dress is perfect for everyday wear. It\'s comfortable, stylish, and easy to care for. The color is vibrant and hasn\'t faded after multiple washes.', 4, 7, 0, 'approved', 9, '2024-12-08 10:10:00'),
(10, 2, 4, 10, 'Great addition to wardrobe', 'Amanda Taylor', 'I\'ve been looking for a dress like this for ages! It\'s exactly what I wanted - comfortable, flattering, and versatile. Perfect for both work and weekend events.', 5, 11, 1, 'approved', 10, '2024-12-07 14:25:00'),
(11, 3, 6, 11, 'Average quality', 'Karen Martinez', 'The dress is okay but nothing special. The fabric quality is average and the fit is loose in some areas. It\'s decent for the price but I expected better based on the photos.', 2, 2, 1, 'approved', 11, '2024-12-06 16:30:00'),
(12, 1, 2, 12, 'Love the color!', 'Nicole Garcia', 'The color of this dress is absolutely gorgeous! It\'s exactly what I was looking for. The fit is good and the fabric feels nice. Very happy with my purchase.', 4, 5, 0, 'approved', 12, '2024-12-05 11:45:00'),
(15, 3, NULL, 4, 'it was good', 'Ammar Chhipa', 'fine product', 4, 0, 1, 'approved', 0, '2025-08-11 13:22:57'),
(16, 2, NULL, 42, 'fine fit', 'Ammar Chhipa', 'the fit was proper', 3, 0, 0, 'approved', 0, '2025-08-13 13:34:29'),
(21, 15, 2, 45, 'Hellooooooo', 'Ammar Chhipa', 'hjgfhjghfghjdsfgshdfgshd', 4, 0, 1, 'approved', 1, '2025-08-22 22:24:21');

-- --------------------------------------------------------

--
-- Table structure for table `ec_users`
--

CREATE TABLE `ec_users` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `address_1` varchar(255) NOT NULL,
  `city` varchar(100) NOT NULL,
  `state` varchar(100) NOT NULL,
  `country` varchar(100) NOT NULL,
  `postal_code` varchar(20) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` enum('admin','user') NOT NULL,
  `status` enum('active','inactive','banned') NOT NULL DEFAULT 'inactive',
  `email_verified` tinyint(1) DEFAULT 0,
  `newsletter_subscription` tinyint(1) DEFAULT 0,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `failed_login_attempts` int(11) DEFAULT 0,
  `last_login_attempt` datetime DEFAULT NULL,
  `account_locked_until` datetime DEFAULT NULL,
  `remember_token` varchar(255) DEFAULT NULL,
  `remember_token_expires` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `login_count` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ec_users`
--

INSERT INTO `ec_users` (`id`, `email`, `password`, `first_name`, `last_name`, `address_1`, `city`, `state`, `country`, `postal_code`, `phone`, `role`, `status`, `email_verified`, `newsletter_subscription`, `reset_token`, `reset_expires`, `created_at`, `updated_at`, `failed_login_attempts`, `last_login_attempt`, `account_locked_until`, `remember_token`, `remember_token_expires`, `last_login`, `login_count`) VALUES
(1, 'john.doe@example.com', 'hashedpassword1', 'John', 'Doe', '123 Street, Downtown', 'Ahmedabad', 'Gujarat', 'India', '380001', '9876543210', 'user', 'active', 1, 1, NULL, NULL, '2025-08-24 18:42:52', '2025-08-24 18:42:52', 0, NULL, NULL, NULL, NULL, '2025-08-24 18:42:52', 15),
(2, 'jane.smith@example.com', 'hashedpassword2', 'Jane', 'Smith', '456 Avenue, Uptown', 'Mumbai', 'Maharashtra', 'India', '400001', '9876501234', 'user', 'active', 1, 0, NULL, NULL, '2025-08-24 18:42:52', '2025-08-24 18:42:52', 0, NULL, NULL, NULL, NULL, '2025-08-24 18:42:52', 10),
(3, 'ali.khan@example.com', 'hashedpassword3', 'Ali', 'Khan', '789 Road, Old City', 'Delhi', 'Delhi', 'India', '110001', '9988776655', 'user', 'inactive', 1, 0, NULL, NULL, '2025-08-24 18:42:52', '2025-08-24 18:42:52', 0, NULL, NULL, NULL, NULL, NULL, 3),
(4, 'sara.patel@example.com', 'hashedpassword4', 'Sara', 'Patel', '12 Block, New Colony', 'Surat', 'Gujarat', 'India', '395001', '9123456789', 'user', 'active', 1, 1, NULL, NULL, '2025-08-24 18:42:52', '2025-08-24 18:42:52', 0, NULL, NULL, NULL, NULL, '2025-08-24 18:42:52', 8),
(5, 'admin@example.com', 'hashedpassword5', 'user', 'User', 'Admin HQ', 'Ahmedabad', 'Gujarat', 'India', '380002', '9000000000', 'user', 'active', 1, 0, NULL, NULL, '2025-08-24 18:42:52', '2025-08-24 18:42:52', 0, NULL, NULL, NULL, NULL, '2025-08-24 18:42:52', 50),
(42, 'ammarchhipa2003@gmail.com', '$2y$12$B3EbKG2dkJHMCfoBo5MxjeOWsE5EYLA5uPH8kJkPFppFr/39ynVTq', 'Ammar', 'Chhipa', 'D3 Banas Flat Opp. SLU College', 'Ahmedabad', 'Gujarat', 'India', '380006', '+917016043900', 'user', 'active', 1, 0, NULL, NULL, '2025-08-12 19:53:01', '2025-09-18 15:45:53', 0, NULL, NULL, 'cd56f9b66e12e7c0d58e285a36ba559a7cc6978b0297b185af11a40dd0a71324', '2025-10-18 15:45:53', '2025-09-18 15:45:53', 0),
(43, 'admin@elegantdresses.com', '$2y$12$LQv3c1ydiCDfNGaFr.gLcO9P0N7v3EV8Gh8v6T5Jh9kHKjF7fGhXi', 'Admin', 'User', '', '', '', '', '', NULL, 'user', 'active', 1, 0, NULL, NULL, '2025-08-15 20:45:53', '2025-08-23 00:23:30', 0, NULL, NULL, NULL, NULL, NULL, 0),
(45, 'ammarchhipa786@gmail.com', '$2y$12$/V4KLpbjopQD3nCEk0oGAOefUOsIDUFe1qJmagsegsT/LYwafKWkW', 'Ammar', 'Chhipa', 'D3 Banas Flat Opp. SLU College', 'Ahmedabad', 'Gujarat', 'India', '380006', '+917016043900', 'admin', 'active', 1, 0, NULL, NULL, '2025-08-15 19:05:45', '2025-09-17 21:58:37', 0, NULL, NULL, NULL, '2025-10-18 00:21:30', '2025-09-18 00:21:30', 0);

-- --------------------------------------------------------

--
-- Table structure for table `ec_wishlist`
--

CREATE TABLE `ec_wishlist` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `size` varchar(10) DEFAULT NULL,
  `color` varchar(30) DEFAULT NULL,
  `added_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ec_wishlist`
--

INSERT INTO `ec_wishlist` (`id`, `user_id`, `product_id`, `size`, `color`, `added_at`) VALUES
(66, 42, 2, NULL, NULL, '2025-08-14 12:41:13'),
(68, 42, 3, NULL, NULL, '2025-08-14 23:42:57'),
(105, 45, 1, NULL, NULL, '2025-09-16 18:05:22');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ec_cart`
--
ALTER TABLE `ec_cart`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_session_id` (`session_id`),
  ADD KEY `idx_product_id` (`product_id`);

--
-- Indexes for table `ec_categories`
--
ALTER TABLE `ec_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_status_homepage` (`status`,`show_on_homepage`);

--
-- Indexes for table `ec_login_attempts`
--
ALTER TABLE `ec_login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email_time` (`email`,`attempt_time`),
  ADD KEY `idx_ip_time` (`ip_address`,`attempt_time`),
  ADD KEY `idx_fingerprint` (`fingerprint`);

--
-- Indexes for table `ec_orders`
--
ALTER TABLE `ec_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `fk_orders_products` (`pid`);

--
-- Indexes for table `ec_order_items`
--
ALTER TABLE `ec_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `fk_order_items_to_product_id` (`product_id`);

--
-- Indexes for table `ec_products`
--
ALTER TABLE `ec_products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_category_status` (`status`),
  ADD KEY `idx_featured_status` (`featured`,`status`),
  ADD KEY `idx_price` (`price`),
  ADD KEY `sku` (`sku`),
  ADD KEY `fk_products_category` (`category_id`);

--
-- Indexes for table `ec_promotions`
--
ALTER TABLE `ec_promotions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ec_testimonials`
--
ALTER TABLE `ec_testimonials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status_sort` (`status`,`sort_order`),
  ADD KEY `idx_product_id` (`product_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_rating` (`rating`);

--
-- Indexes for table `ec_users`
--
ALTER TABLE `ec_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_email_status` (`email`),
  ADD KEY `idx_reset_token` (`reset_token`),
  ADD KEY `idx_reset_expires` (`reset_expires`),
  ADD KEY `idx_remember_token` (`remember_token`),
  ADD KEY `idx_account_locked` (`account_locked_until`),
  ADD KEY `idx_last_login_attempt` (`last_login_attempt`);

--
-- Indexes for table `ec_wishlist`
--
ALTER TABLE `ec_wishlist`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `product_id` (`product_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `ec_cart`
--
ALTER TABLE `ec_cart`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=76;

--
-- AUTO_INCREMENT for table `ec_categories`
--
ALTER TABLE `ec_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `ec_login_attempts`
--
ALTER TABLE `ec_login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `ec_orders`
--
ALTER TABLE `ec_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `ec_order_items`
--
ALTER TABLE `ec_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `ec_products`
--
ALTER TABLE `ec_products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `ec_promotions`
--
ALTER TABLE `ec_promotions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `ec_testimonials`
--
ALTER TABLE `ec_testimonials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `ec_users`
--
ALTER TABLE `ec_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `ec_wishlist`
--
ALTER TABLE `ec_wishlist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=108;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `ec_cart`
--
ALTER TABLE `ec_cart`
  ADD CONSTRAINT `ec_cart_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `ec_users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ec_cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `ec_products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ec_orders`
--
ALTER TABLE `ec_orders`
  ADD CONSTRAINT `ec_orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `ec_users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_orders_products` FOREIGN KEY (`pid`) REFERENCES `ec_products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `ec_order_items`
--
ALTER TABLE `ec_order_items`
  ADD CONSTRAINT `ec_order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `ec_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_order_items_to_product_id` FOREIGN KEY (`product_id`) REFERENCES `ec_products` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `ec_products`
--
ALTER TABLE `ec_products`
  ADD CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `ec_categories` (`id`);

--
-- Constraints for table `ec_wishlist`
--
ALTER TABLE `ec_wishlist`
  ADD CONSTRAINT `ec_wishlist_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `ec_users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ec_wishlist_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `ec_products` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
