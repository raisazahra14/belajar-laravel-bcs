-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: db_logistik
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `db_logistik`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `db_logistik` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;

USE `db_logistik`;

--
-- Table structure for table `barang`
--

DROP TABLE IF EXISTS `barang`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `barang` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kode_barang` varchar(255) NOT NULL,
  `nama_barang` varchar(255) NOT NULL,
  `kategori` varchar(255) NOT NULL,
  `stok` int(11) NOT NULL DEFAULT 0,
  `satuan` varchar(255) NOT NULL,
  `lokasi` varchar(255) NOT NULL,
  `foto_barang` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `barang_kode_barang_unique` (`kode_barang`)
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `barang`
--

LOCK TABLES `barang` WRITE;
/*!40000 ALTER TABLE `barang` DISABLE KEYS */;
INSERT INTO `barang` VALUES (1,'BRG-2180nd','Keyboard Wireless','Peralatan',83,'Unit','Gudang C','barang/ILmC2Zj9y6TfQ6ocfzbMcnnUMIrDYCkwM8xZsdCt.jpg','2026-08-19 10:21:15','2026-08-19 23:32:25'),(2,'BRG-6394yz','Stapler Besar','ATK',61,'Unit','Rak A2',NULL,'2026-08-19 10:21:15',NULL),(3,'BRG-7284sv','Kertas HVS A4','Elektronik',24,'Pack','Gudang A',NULL,'2026-08-19 10:21:15','2026-08-19 23:32:56'),(4,'BRG-0846wo','Meja Kerja Kayu','Furniture',57,'Unit','Ruang IT',NULL,'2026-08-19 10:21:15',NULL),(5,'BRG-0083fy','Meja Kerja Kayu','Furniture',47,'Unit','Gudang C',NULL,'2026-08-19 10:21:15',NULL),(6,'BRG-8822pc','Monitor LED 24 Inch','Peralatan',26,'Unit','Gudang B',NULL,'2026-08-19 10:21:15',NULL),(7,'BRG-3647td','Stapler Besar','ATK',97,'Unit','Ruang IT',NULL,'2026-08-19 10:21:15',NULL),(8,'BRG-4154ql','Mouse Optik USB','Elektronik',22,'Unit','Gudang B',NULL,'2026-08-19 10:21:15',NULL),(9,'BRG-4681xi','Stapler Besar','ATK',65,'Unit','Gudang B',NULL,'2026-08-19 10:21:15',NULL),(10,'BRG-2853wv','Stapler Besar','Furniture',97,'Unit','Gudang B',NULL,'2026-08-19 10:21:15',NULL),(11,'BRG-0804az','Keyboard Wireless','Jaringan',49,'Unit','Ruang IT',NULL,'2026-08-19 10:21:15',NULL),(12,'BRG-9875lm','Meja Kerja Kayu','Peralatan',66,'Unit','Gudang B',NULL,'2026-08-19 10:21:15',NULL),(13,'BRG-1581tk','Keyboard Wireless','Elektronik',89,'Unit','Gudang C',NULL,'2026-08-19 10:21:15',NULL),(14,'BRG-4475wg','Keyboard Wireless','Furniture',9,'Unit','Gudang C',NULL,'2026-08-19 10:21:15',NULL),(15,'BRG-5801ff','Stapler Besar','Bahan Baku',66,'Unit','Rak B3',NULL,'2026-08-19 10:21:15',NULL),(16,'BRG-2214me','Proyektor Portable','Peralatan',34,'Unit','Gudang B',NULL,'2026-08-19 10:21:15',NULL),(17,'BRG-1459km','Pulpen Gel Hitam','Furniture',54,'Pcs','Gudang C',NULL,'2026-08-19 10:21:15',NULL),(18,'BRG-4512hp','Pulpen Gel Hitam','ATK',96,'Pcs','Rak A1',NULL,'2026-08-19 10:21:15',NULL),(19,'BRG-8922gp','Meja Kerja Kayu','Bahan Baku',72,'Unit','Rak B3',NULL,'2026-08-19 10:21:15',NULL),(20,'BRG-8510ig','Keyboard Wireless','ATK',57,'Unit','Rak A1',NULL,'2026-08-19 10:21:15',NULL),(21,'BRG-6489ab','Proyektor Portable','Peralatan',68,'Unit','Gudang C',NULL,'2026-08-19 10:21:15',NULL),(22,'BRG-2625rk','Mouse Optik USB','Bahan Baku',50,'Unit','Rak B3',NULL,'2026-08-19 10:21:15',NULL),(23,'BRG-4516ic','Kursi Kerja Ergonomis','Furniture',1,'Unit','Gudang C',NULL,'2026-08-19 10:21:15',NULL),(24,'BRG-3710wa','Kabel LAN Cat6','Peralatan',26,'Pcs','Gudang B',NULL,'2026-08-19 10:21:15',NULL),(25,'BRG-8010cd','Pulpen Gel Hitam','Jaringan',0,'Pcs','Ruang IT',NULL,'2026-08-19 10:21:15','2026-08-19 20:07:11'),(26,'BRG-2907ng','Keyboard Wireless','Peralatan',17,'Unit','Gudang C',NULL,'2026-08-19 10:21:15',NULL),(27,'BRG-7036xj','Kursi Kerja Ergonomis','Bahan Baku',22,'Unit','Rak B3',NULL,'2026-08-19 10:21:15',NULL),(28,'BRG-2967qx','Mouse Optik USB','Jaringan',66,'Unit','Ruang IT',NULL,'2026-08-19 10:21:15',NULL),(29,'BRG-2439rb','Pulpen Gel Hitam','ATK',86,'Pcs','Rak A1',NULL,'2026-08-19 10:21:15',NULL),(30,'BRG-1673uj','Meja Kerja Kayu','ATK',40,'Unit','Rak A2',NULL,'2026-08-19 10:21:15',NULL),(31,'BRG-9628an','Laptop Lenovo ThinkPad','Peralatan',80,'Unit','Gudang B',NULL,'2026-08-19 10:21:15',NULL),(32,'BRG-1293rm','Monitor LED 24 Inch','Furniture',98,'Unit','Gudang C',NULL,'2026-08-19 10:21:15',NULL),(33,'BRG-2003ph','Laptop Lenovo ThinkPad','Furniture',82,'Unit','Gudang B',NULL,'2026-08-19 10:21:15',NULL),(34,'BRG-6996fw','Meja Kerja Kayu','Furniture',21,'Unit','Gudang C',NULL,'2026-08-19 10:21:15',NULL),(35,'BRG-5972lg','Keyboard Wireless','Bahan Baku',30,'Unit','Rak B2',NULL,'2026-08-19 10:21:15',NULL),(36,'BRG-0044uf','Kertas HVS A4','Bahan Baku',64,'Pack','Rak B3',NULL,'2026-08-19 10:21:15',NULL),(37,'BRG-4983ow','Mouse Optik USB','Furniture',1,'Unit','Gudang C',NULL,'2026-08-19 10:21:15',NULL),(38,'BRG-3592se','Kabel LAN Cat6','Jaringan',60,'Pcs','Ruang IT',NULL,'2026-08-19 10:21:15',NULL),(39,'BRG-5957fx','Kabel LAN Cat6','Peralatan',92,'Pcs','Gudang B',NULL,'2026-08-19 10:21:15',NULL),(40,'BRG-4666gf','Meja Kerja Kayu','Jaringan',74,'Unit','Ruang IT',NULL,'2026-08-19 10:21:15',NULL),(41,'BRG-3834yh','Pulpen Gel Hitam','Bahan Baku',69,'Pcs','Rak B2',NULL,'2026-08-19 10:21:15',NULL),(42,'BRG-1988ou','Pulpen Gel Hitam','Peralatan',87,'Pcs','Gudang C',NULL,'2026-08-19 10:21:15',NULL),(43,'BRG-9378kf','Meja Kerja Kayu','ATK',4,'Unit','Rak A2',NULL,'2026-08-19 10:21:15',NULL),(44,'BRG-3044co','Kertas HVS A4','Peralatan',31,'Pack','Gudang B',NULL,'2026-08-19 10:21:15',NULL),(45,'BRG-7031kr','Kabel LAN Cat6','ATK',99,'Pcs','Rak A1',NULL,'2026-08-19 10:21:15',NULL),(46,'BRG-1026zb','Pulpen Gel Hitam','ATK',58,'Pcs','Rak A1',NULL,'2026-08-19 10:21:15',NULL),(47,'BRG-7927rd','Stapler Besar','Elektronik',90,'Unit','Gudang C',NULL,'2026-08-19 10:21:15',NULL),(48,'BRG-1524qr','Proyektor Portable','Elektronik',83,'Unit','Ruang IT',NULL,'2026-08-19 10:21:15',NULL),(49,'BRG-8634nc','Stapler Besar','Elektronik',98,'Unit','Ruang IT',NULL,'2026-08-19 10:21:15',NULL),(50,'BRG-3612lz','Stapler Besar','Peralatan',47,'Unit','Gudang B',NULL,'2026-08-19 10:21:15',NULL),(51,'BRG460','Pena','ATK',12,'Unit','Rak A1',NULL,'2026-08-19 20:05:20','2026-08-19 20:05:45'),(52,'BRG518','Pulpen Gel Biru','ATK',12,'Unit','Rak A1',NULL,'2026-08-19 20:06:38',NULL),(53,'BRG119','Monitor LG 2','Elektronik',13,'Unit','Gudang A','barang/ID5ao4o3tM8EdS24rC3vGm5TCfOcv1UerXkrfKeU.jpg','2026-08-19 23:33:44',NULL),(54,'BRG154','Laptop ThinkPad X3','Elektronik',7,'Unit','Gudang A','barang/N1X4cSSulcKL3a7w8sPkuFWOsV8GmVtUnaAahuXc.webp','2026-08-20 20:36:33',NULL),(55,'BRG172','Kursi Kerja','Furniture',9,'Unit','Gudang C',NULL,'2026-08-21 02:35:29',NULL);
/*!40000 ALTER TABLE `barang` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
INSERT INTO `cache` VALUES ('laravel_cache_spatie.permission.cache','a:3:{s:5:\"alias\";a:0:{}s:11:\"permissions\";a:0:{}s:5:\"roles\";a:0:{}}',1787812102);
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2026_08_14_000000_create_barang_table',1),(5,'2026_08_14_032429_create_stok_histories_table',1),(6,'2026_08_14_070616_create_stok_transactions_table',1),(7,'2026_08_18_000000_add_role_to_users_table',1),(8,'2026_08_19_171054_add_foto_barang_to_barang_table',1),(9,'2026_08_20_021107_add_deleted_at_to_barang_table',2),(10,'2026_08_20_034933_create_permission_tables',3),(11,'2026_08_20_150000_create_personal_access_tokens_table',4),(12,'2026_08_21_000000_migrate_stok_histories_to_stok_transactions',4),(13,'2026_08_21_180000_normalize_barang_units',5);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_permissions`
--

DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_permissions`
--

LOCK TABLES `model_has_permissions` WRITE;
/*!40000 ALTER TABLE `model_has_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `model_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_roles`
--

DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_roles`
--

LOCK TABLES `model_has_roles` WRITE;
/*!40000 ALTER TABLE `model_has_roles` DISABLE KEYS */;
INSERT INTO `model_has_roles` VALUES (1,'App\\Models\\User',1),(2,'App\\Models\\User',2),(3,'App\\Models\\User',3);
/*!40000 ALTER TABLE `model_has_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_has_permissions`
--

DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_has_permissions`
--

LOCK TABLES `role_has_permissions` WRITE;
/*!40000 ALTER TABLE `role_has_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `role_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'Admin','web','2026-08-19 21:17:09','2026-08-19 21:17:09'),(2,'Staff Gudang','web','2026-08-19 21:17:09','2026-08-19 21:17:09'),(3,'Manager','web','2026-08-19 21:17:09','2026-08-19 21:17:09');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stok_transactions`
--

DROP TABLE IF EXISTS `stok_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stok_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `barang_id` bigint(20) unsigned NOT NULL,
  `jenis` enum('masuk','keluar') NOT NULL,
  `jumlah` int(11) NOT NULL,
  `keterangan` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `stok_transactions_barang_id_foreign` (`barang_id`),
  CONSTRAINT `stok_transactions_barang_id_foreign` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stok_transactions`
--

LOCK TABLES `stok_transactions` WRITE;
/*!40000 ALTER TABLE `stok_transactions` DISABLE KEYS */;
INSERT INTO `stok_transactions` VALUES (1,53,'masuk',3,'tambah stok','2026-08-19 23:34:59','2026-08-19 23:34:59');
/*!40000 ALTER TABLE `stok_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` varchar(255) NOT NULL DEFAULT 'staff',
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Administrator','admin@logistikku.test','admin',NULL,'$2y$12$/MG6KSNc7lk9tOFlHmTQCu9YTmqR3LYVjDlQkPfapFIRCWlArZ25m',NULL,'2026-08-19 10:21:11','2026-08-19 10:21:11'),(2,'Staff Gudang','staff@logistikku.test','staff',NULL,'$2y$12$X1Oq0Jhb4drn9KK/imu58.mNemSMAoJrGXPXl9Hdm.xisHkp5Wqze',NULL,'2026-08-19 10:21:12','2026-08-19 10:21:12'),(3,'Manager','manager@logistikku.test','manager',NULL,'$2y$12$cJy29jfdog.4jZkS4anYvO2HqrSfhG18xJ3MSViLEJxA7JVNy6mQi',NULL,'2026-08-19 21:29:21','2026-08-19 21:29:21');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-30 23:14:23
