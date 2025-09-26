-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Sep 21, 2025 at 02:42 PM
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
-- Database: `mesmtf`
--

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `id` int(10) UNSIGNED NOT NULL,
  `patient_id` int(10) UNSIGNED NOT NULL,
  `doctor_id` int(10) UNSIGNED NOT NULL,
  `scheduled_at` datetime NOT NULL,
  `duration_minutes` smallint(5) UNSIGNED NOT NULL DEFAULT 15,
  `status` enum('booked','cancelled','completed') NOT NULL DEFAULT 'booked',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `target_type` varchar(32) DEFAULT NULL,
  `target_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `auth_logs`
--

CREATE TABLE `auth_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `meta` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `diagnoses`
--

CREATE TABLE `diagnoses` (
  `id` int(10) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `patient_id` int(10) UNSIGNED NOT NULL,
  `evaluator_user_id` int(10) UNSIGNED DEFAULT NULL,
  `payload` text NOT NULL,
  `result` text NOT NULL,
  `result_disease_id` smallint(5) UNSIGNED DEFAULT NULL,
  `confidence` decimal(5,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `synced_from_client` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `diseases`
--

CREATE TABLE `diseases` (
  `id` smallint(5) UNSIGNED NOT NULL,
  `code` varchar(60) NOT NULL,
  `name` varchar(128) NOT NULL,
  `description` text DEFAULT NULL,
  `threshold` int(10) UNSIGNED NOT NULL DEFAULT 5
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `diseases`
--

INSERT INTO `diseases` (`id`, `code`, `name`, `description`, `threshold`) VALUES
(1, 'MALARIA', 'Malaria', 'Parasitic infection transmitted by mosquitoes', 5),
(2, 'TYPHOID', 'Typhoid Fever', 'Systemic infection by Salmonella typhi', 5),
(3, 'TB', 'Tuberculosis', 'Bacterial infection affecting lungs', 5);

-- --------------------------------------------------------

--
-- Table structure for table `disease_recommended_drugs`
--

CREATE TABLE `disease_recommended_drugs` (
  `id` int(10) UNSIGNED NOT NULL,
  `disease_id` smallint(5) UNSIGNED NOT NULL,
  `drug_id` int(10) UNSIGNED NOT NULL,
  `notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `disease_recommended_drugs`
--

INSERT INTO `disease_recommended_drugs` (`id`, `disease_id`, `drug_id`, `notes`) VALUES
(1, 1, 2, 'First-line per local protocol'),
(2, 1, 1, 'For fever management'),
(3, 2, 3, 'Empiric therapy (adjust per guid.)'),
(4, 2, 4, 'Alternative per physician');

-- --------------------------------------------------------

--
-- Table structure for table `disease_rule_symptoms`
--

CREATE TABLE `disease_rule_symptoms` (
  `id` int(10) UNSIGNED NOT NULL,
  `disease_id` smallint(5) UNSIGNED NOT NULL,
  `symptom_id` smallint(5) UNSIGNED NOT NULL,
  `weight` tinyint(3) UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `disease_rule_symptoms`
--

INSERT INTO `disease_rule_symptoms` (`id`, `disease_id`, `symptom_id`, `weight`) VALUES
(1, 1, 1, 5),
(2, 1, 3, 5),
(3, 1, 4, 3),
(4, 1, 5, 3),
(5, 1, 6, 2),
(6, 1, 7, 2),
(7, 1, 11, 2),
(8, 2, 2, 5),
(9, 2, 8, 4),
(10, 2, 9, 4),
(11, 2, 10, 3),
(12, 2, 5, 2),
(13, 2, 11, 2),
(14, 2, 6, 2),
(15, 3, 12, 5),
(16, 3, 13, 4),
(17, 3, 14, 4),
(18, 3, 1, 2);

-- --------------------------------------------------------

--
-- Table structure for table `drugs`
--

CREATE TABLE `drugs` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(80) NOT NULL,
  `name` varchar(128) NOT NULL,
  `description` text DEFAULT NULL,
  `stock` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `reorder_level` int(10) UNSIGNED NOT NULL DEFAULT 10,
  `unit` varchar(30) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `drugs`
--

INSERT INTO `drugs` (`id`, `code`, `name`, `description`, `stock`, `reorder_level`, `unit`, `created_at`, `updated_at`) VALUES
(1, 'PARA500', 'Paracetamol 500mg', 'Analgesic/antipyretic', 500, 50, 'tablet', '2025-09-21 11:06:41', '2025-09-21 11:06:41'),
(2, 'ACT-COART', 'Artemether/Lumefantrine', 'ACT for Malaria', 200, 30, 'tablet', '2025-09-21 11:06:41', '2025-09-21 11:06:41'),
(3, 'CIPRO500', 'Ciprofloxacin 500mg', 'Antibiotic', 150, 20, 'tablet', '2025-09-21 11:06:41', '2025-09-21 11:06:41'),
(4, 'AZI500', 'Azithromycin 500mg', 'Antibiotic', 100, 20, 'tablet', '2025-09-21 11:06:41', '2025-09-21 11:06:41');

-- --------------------------------------------------------

--
-- Table structure for table `drug_administrations`
--

CREATE TABLE `drug_administrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `patient_id` int(10) UNSIGNED NOT NULL,
  `nurse_id` int(10) UNSIGNED NOT NULL,
  `prescription_item_id` int(10) UNSIGNED NOT NULL,
  `administered_at` datetime NOT NULL DEFAULT current_timestamp(),
  `dose` varchar(64) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patients`
--

CREATE TABLE `patients` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `external_identifier` varchar(100) DEFAULT NULL,
  `first_name` varchar(80) NOT NULL,
  `last_name` varchar(80) NOT NULL,
  `gender` enum('male','female','other') DEFAULT 'other',
  `date_of_birth` date DEFAULT NULL,
  `contact_phone` varchar(30) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pharmacy_actions`
--

CREATE TABLE `pharmacy_actions` (
  `id` int(10) UNSIGNED NOT NULL,
  `prescription_item_id` int(10) UNSIGNED NOT NULL,
  `pharmacist_id` int(10) UNSIGNED NOT NULL,
  `action` enum('dispensed','reversed','adjusted') NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prescriptions`
--

CREATE TABLE `prescriptions` (
  `id` int(10) UNSIGNED NOT NULL,
  `patient_id` int(10) UNSIGNED NOT NULL,
  `doctor_id` int(10) UNSIGNED NOT NULL,
  `appointment_id` int(10) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','dispensed','partially_dispensed','cancelled') NOT NULL DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prescription_items`
--

CREATE TABLE `prescription_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `prescription_id` int(10) UNSIGNED NOT NULL,
  `drug_id` int(10) UNSIGNED NOT NULL,
  `dosage` varchar(80) NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `name` varchar(32) NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'admin', 'System administrator'),
(2, 'receptionist', 'Front desk'),
(3, 'patient', 'Patient account'),
(4, 'doctor', 'Medical doctor'),
(5, 'nurse', 'Nurse'),
(6, 'pharmacist', 'Pharmacist');

-- --------------------------------------------------------

--
-- Table structure for table `symptoms`
--

CREATE TABLE `symptoms` (
  `id` smallint(5) UNSIGNED NOT NULL,
  `slug` varchar(80) NOT NULL,
  `label` varchar(128) NOT NULL,
  `category_id` tinyint(3) UNSIGNED NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `symptoms`
--

INSERT INTO `symptoms` (`id`, `slug`, `label`, `category_id`, `is_active`) VALUES
(1, 'fever', 'Fever', 1, 1),
(2, 'sustained_fever', 'Sustained Fever', 1, 1),
(3, 'chills', 'Chills', 1, 1),
(4, 'sweating', 'Sweating', 1, 1),
(5, 'headache', 'Headache', 3, 1),
(6, 'nausea', 'Nausea', 2, 1),
(7, 'vomiting', 'Vomiting', 2, 1),
(8, 'abdominal_pain', 'Abdominal Pain', 2, 1),
(9, 'diarrhea', 'Diarrhea', 2, 1),
(10, 'constipation', 'Constipation', 2, 1),
(11, 'weakness', 'Weakness/Fatigue', 1, 1),
(12, 'cough', 'Cough', 1, 1),
(13, 'night_sweats', 'Night Sweats', 1, 1),
(14, 'weight_loss', 'Unintentional Weight Loss', 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `symptom_categories`
--

CREATE TABLE `symptom_categories` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `code` varchar(16) NOT NULL,
  `description` varchar(128) DEFAULT NULL,
  `weight` tinyint(3) UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `symptom_categories`
--

INSERT INTO `symptom_categories` (`id`, `code`, `description`, `weight`) VALUES
(1, 'GEN', 'General', 1),
(2, 'GI', 'Gastrointestinal', 1),
(3, 'NEURO', 'Neurological', 1);

-- --------------------------------------------------------

--
-- Table structure for table `sync_queue`
--

CREATE TABLE `sync_queue` (
  `id` int(10) UNSIGNED NOT NULL,
  `client_uuid` char(36) NOT NULL,
  `item_type` varchar(40) NOT NULL,
  `payload` text NOT NULL,
  `status` enum('pending','synced','failed') NOT NULL DEFAULT 'pending',
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `treatments`
--

CREATE TABLE `treatments` (
  `id` int(10) UNSIGNED NOT NULL,
  `disease_id` smallint(5) UNSIGNED NOT NULL,
  `name` varchar(128) NOT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `treatments`
--

INSERT INTO `treatments` (`id`, `disease_id`, `name`, `notes`) VALUES
(1, 1, 'ACT (Artemisinin-based Combination Therapy)', 'Follow national guidelines'),
(2, 2, 'Antibiotics (e.g., Ciprofloxacin/Azithromycin per guidelines)', 'Culture sensitivity where applicable');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(60) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role_id` tinyint(3) UNSIGNED NOT NULL,
  `full_name` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `specialty` varchar(128) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_doctor_slot` (`doctor_id`,`scheduled_at`),
  ADD KEY `idx_appointments_patient` (`patient_id`),
  ADD KEY `idx_appointments_doctor` (`doctor_id`),
  ADD KEY `idx_appointments_time` (`doctor_id`,`scheduled_at`),
  ADD KEY `fk_appointments_creator` (`created_by`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_user` (`user_id`),
  ADD KEY `idx_audit_target` (`target_type`,`target_id`);

--
-- Indexes for table `auth_logs`
--
ALTER TABLE `auth_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_auth_user` (`user_id`);

--
-- Indexes for table `diagnoses`
--
ALTER TABLE `diagnoses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uuid` (`uuid`),
  ADD KEY `idx_diagnoses_patient` (`patient_id`),
  ADD KEY `idx_diagnoses_created` (`created_at`),
  ADD KEY `fk_diagnoses_user` (`evaluator_user_id`),
  ADD KEY `fk_diagnoses_result_disease` (`result_disease_id`);

--
-- Indexes for table `diseases`
--
ALTER TABLE `diseases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `disease_recommended_drugs`
--
ALTER TABLE `disease_recommended_drugs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_drd` (`disease_id`,`drug_id`),
  ADD KEY `fk_drd_drug` (`drug_id`);

--
-- Indexes for table `disease_rule_symptoms`
--
ALTER TABLE `disease_rule_symptoms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_disease_symptom` (`disease_id`,`symptom_id`),
  ADD KEY `idx_drs_disease` (`disease_id`),
  ADD KEY `idx_drs_symptom` (`symptom_id`);

--
-- Indexes for table `drugs`
--
ALTER TABLE `drugs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_drugs_name` (`name`);

--
-- Indexes for table `drug_administrations`
--
ALTER TABLE `drug_administrations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_da_patient` (`patient_id`),
  ADD KEY `idx_da_item` (`prescription_item_id`),
  ADD KEY `fk_da_nurse` (`nurse_id`);

--
-- Indexes for table `patients`
--
ALTER TABLE `patients`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_patients_user` (`user_id`),
  ADD KEY `idx_patients_name` (`last_name`,`first_name`),
  ADD KEY `fk_patients_creator` (`created_by`);

--
-- Indexes for table `pharmacy_actions`
--
ALTER TABLE `pharmacy_actions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pa_item` (`prescription_item_id`),
  ADD KEY `fk_pa_pharmacist` (`pharmacist_id`);

--
-- Indexes for table `prescriptions`
--
ALTER TABLE `prescriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_prescriptions_patient` (`patient_id`),
  ADD KEY `idx_prescriptions_doctor` (`doctor_id`),
  ADD KEY `idx_prescriptions_status` (`status`),
  ADD KEY `fk_prescriptions_appointment` (`appointment_id`);

--
-- Indexes for table `prescription_items`
--
ALTER TABLE `prescription_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pi_prescription` (`prescription_id`),
  ADD KEY `idx_pi_drug` (`drug_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `symptoms`
--
ALTER TABLE `symptoms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_symptoms_cat` (`category_id`);

--
-- Indexes for table `symptom_categories`
--
ALTER TABLE `symptom_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `sync_queue`
--
ALTER TABLE `sync_queue`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sync_uuid_type` (`client_uuid`,`item_type`);

--
-- Indexes for table `treatments`
--
ALTER TABLE `treatments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_treatments_disease` (`disease_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_role` (`role_id`),
  ADD KEY `idx_users_username` (`username`),
  ADD KEY `idx_users_email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `auth_logs`
--
ALTER TABLE `auth_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `diagnoses`
--
ALTER TABLE `diagnoses`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `diseases`
--
ALTER TABLE `diseases`
  MODIFY `id` smallint(5) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `disease_recommended_drugs`
--
ALTER TABLE `disease_recommended_drugs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `disease_rule_symptoms`
--
ALTER TABLE `disease_rule_symptoms`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `drugs`
--
ALTER TABLE `drugs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `drug_administrations`
--
ALTER TABLE `drug_administrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pharmacy_actions`
--
ALTER TABLE `pharmacy_actions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prescriptions`
--
ALTER TABLE `prescriptions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prescription_items`
--
ALTER TABLE `prescription_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` tinyint(3) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `symptoms`
--
ALTER TABLE `symptoms`
  MODIFY `id` smallint(5) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `symptom_categories`
--
ALTER TABLE `symptom_categories`
  MODIFY `id` tinyint(3) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `sync_queue`
--
ALTER TABLE `sync_queue`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `treatments`
--
ALTER TABLE `treatments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `fk_appointments_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_appointments_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_appointments_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `diagnoses`
--
ALTER TABLE `diagnoses`
  ADD CONSTRAINT `fk_diagnoses_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_diagnoses_result_disease` FOREIGN KEY (`result_disease_id`) REFERENCES `diseases` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_diagnoses_user` FOREIGN KEY (`evaluator_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `disease_recommended_drugs`
--
ALTER TABLE `disease_recommended_drugs`
  ADD CONSTRAINT `fk_drd_disease` FOREIGN KEY (`disease_id`) REFERENCES `diseases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_drd_drug` FOREIGN KEY (`drug_id`) REFERENCES `drugs` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `disease_rule_symptoms`
--
ALTER TABLE `disease_rule_symptoms`
  ADD CONSTRAINT `fk_drs_disease` FOREIGN KEY (`disease_id`) REFERENCES `diseases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_drs_symptom` FOREIGN KEY (`symptom_id`) REFERENCES `symptoms` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `drug_administrations`
--
ALTER TABLE `drug_administrations`
  ADD CONSTRAINT `fk_da_item` FOREIGN KEY (`prescription_item_id`) REFERENCES `prescription_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_da_nurse` FOREIGN KEY (`nurse_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_da_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `patients`
--
ALTER TABLE `patients`
  ADD CONSTRAINT `fk_patients_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_patients_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `pharmacy_actions`
--
ALTER TABLE `pharmacy_actions`
  ADD CONSTRAINT `fk_pa_item` FOREIGN KEY (`prescription_item_id`) REFERENCES `prescription_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pa_pharmacist` FOREIGN KEY (`pharmacist_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `prescriptions`
--
ALTER TABLE `prescriptions`
  ADD CONSTRAINT `fk_prescriptions_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_prescriptions_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_prescriptions_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `prescription_items`
--
ALTER TABLE `prescription_items`
  ADD CONSTRAINT `fk_pi_drug` FOREIGN KEY (`drug_id`) REFERENCES `drugs` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pi_prescription` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `symptoms`
--
ALTER TABLE `symptoms`
  ADD CONSTRAINT `fk_symptoms_category` FOREIGN KEY (`category_id`) REFERENCES `symptom_categories` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `treatments`
--
ALTER TABLE `treatments`
  ADD CONSTRAINT `fk_treatments_disease` FOREIGN KEY (`disease_id`) REFERENCES `diseases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
