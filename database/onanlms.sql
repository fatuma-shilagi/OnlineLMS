-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 21, 2026 at 10:22 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `onanlms`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(255) NOT NULL,
  `module` varchar(100) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `action`, `module`, `details`, `ip_address`, `created_at`) VALUES
(1, 5, 'Logged in', 'Auth', 'student logged in', '127.0.0.1', '2026-06-21 07:11:10'),
(2, 5, 'Logged in', 'Auth', 'student logged in', '127.0.0.1', '2026-06-21 07:18:59'),
(3, 2, 'Logged in', 'Auth', 'lecturer logged in', '127.0.0.1', '2026-06-21 07:24:50'),
(4, 2, 'Logged in', 'Auth', 'lecturer logged in', '127.0.0.1', '2026-06-21 08:06:49'),
(5, 1, 'Logged in', 'Auth', 'admin logged in', '127.0.0.1', '2026-06-21 12:35:02'),
(6, 1, 'Added course', 'Courses', 'Added: ccvf', '127.0.0.1', '2026-06-21 12:50:42'),
(7, 1, 'Logged in', 'Auth', 'admin logged in', '127.0.0.1', '2026-06-21 13:05:43'),
(8, 1, 'Deleted user', 'Users', 'Deleted: Prof. Sarah Kato', '127.0.0.1', '2026-06-21 13:06:06'),
(9, 1, 'Added course', 'Courses', 'Added: rtguo', '127.0.0.1', '2026-06-21 13:07:47'),
(10, 2, 'Logged in', 'Auth', 'lecturer logged in', '127.0.0.1', '2026-06-21 13:08:32'),
(11, 4, 'Logged in', 'Auth', 'student logged in', '127.0.0.1', '2026-06-21 13:15:30'),
(12, 1, 'Logged in', 'Auth', 'admin logged in', '127.0.0.1', '2026-06-21 13:36:21'),
(13, 4, 'Logged in', 'Auth', 'student logged in', '127.0.0.1', '2026-06-21 19:01:50'),
(14, 2, 'Logged in', 'Auth', 'lecturer logged in', '127.0.0.1', '2026-06-21 19:22:12'),
(15, 1, 'Logged in', 'Auth', 'admin logged in', '127.0.0.1', '2026-06-21 19:23:04'),
(16, 1, 'Added user', 'Users', 'Added: Rich Maunyama (student)', '127.0.0.1', '2026-06-21 19:57:59'),
(17, 7, 'Logged in', 'Auth', 'student logged in', '127.0.0.1', '2026-06-21 19:59:13'),
(18, 1, 'Logged in', 'Auth', 'admin logged in', '127.0.0.1', '2026-06-21 20:08:19'),
(19, 4, 'Logged in', 'Auth', 'student logged in', '127.0.0.1', '2026-06-21 20:09:03'),
(20, 1, 'Logged in', 'Auth', 'admin logged in', '127.0.0.1', '2026-06-21 20:16:40'),
(21, 1, 'Added user', 'Users', 'Added: Fatuma Shilagi (lecturer)', '127.0.0.1', '2026-06-21 20:18:34'),
(22, 1, 'Added course', 'Courses', 'Added: cyber', '127.0.0.1', '2026-06-21 20:19:13'),
(23, 1, 'Added course', 'Courses', 'Added: DIGITAL', '127.0.0.1', '2026-06-21 20:19:32'),
(24, 8, 'Logged in', 'Auth', 'lecturer logged in', '127.0.0.1', '2026-06-21 20:20:09');

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text NOT NULL,
  `course_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `due_date` datetime NOT NULL,
  `total_marks` int(11) DEFAULT 100,
  `file_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `status` enum('active','closed','draft') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assignments`
--

INSERT INTO `assignments` (`id`, `title`, `description`, `course_id`, `created_by`, `due_date`, `total_marks`, `file_name`, `file_path`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Python Calculator', 'Build a simple calculator using Python functions', 1, 2, '2026-06-28 08:27:47', 100, NULL, NULL, 'active', '2026-06-21 06:27:47', '2026-06-21 06:27:47'),
(2, 'Database Schema', 'Design a database schema for a hospital system', 2, 2, '2026-07-01 08:27:47', 100, NULL, NULL, 'active', '2026-06-21 06:27:47', '2026-06-21 06:27:47');

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `id` int(11) NOT NULL,
  `course_code` varchar(20) NOT NULL,
  `course_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `lecturer_id` int(11) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `courses`
--

INSERT INTO `courses` (`id`, `course_code`, `course_name`, `description`, `lecturer_id`, `status`, `created_at`, `updated_at`) VALUES
(1, 'CS101', 'Introduction to Programming', 'Basics of programming using Python', 2, 'active', '2026-06-21 06:27:47', '2026-06-21 06:27:47'),
(2, 'CS202', 'Database Management Systems', 'SQL, normalization, and database design', 2, 'active', '2026-06-21 06:27:47', '2026-06-21 06:27:47'),
(6, 'ZXGHFD,IK', 'rtguo', 'ddjgk', 2, 'active', '2026-06-21 13:07:47', '2026-06-21 13:07:47'),
(7, 'BIO', 'cyber', 'jkhj', 8, 'active', '2026-06-21 20:19:13', '2026-06-21 20:19:13'),
(8, 'CYU', 'DIGITAL', 'TFKYG', 8, 'active', '2026-06-21 20:19:32', '2026-06-21 20:19:32');

-- --------------------------------------------------------

--
-- Table structure for table `course_enrollments`
--

CREATE TABLE `course_enrollments` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('enrolled','dropped','completed') DEFAULT 'enrolled'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `course_enrollments`
--

INSERT INTO `course_enrollments` (`id`, `student_id`, `course_id`, `enrolled_at`, `status`) VALUES
(1, 4, 1, '2026-06-21 06:27:47', 'enrolled'),
(2, 4, 2, '2026-06-21 06:27:47', 'enrolled'),
(4, 5, 1, '2026-06-21 06:27:47', 'enrolled'),
(7, 6, 2, '2026-06-21 06:27:47', 'enrolled');

-- --------------------------------------------------------

--
-- Table structure for table `grades`
--

CREATE TABLE `grades` (
  `id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `marks_obtained` decimal(5,2) NOT NULL,
  `total_marks` int(11) NOT NULL,
  `feedback` text DEFAULT NULL,
  `graded_by` int(11) NOT NULL,
  `graded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notes`
--

CREATE TABLE `notes` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` varchar(50) DEFAULT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `course_id` int(11) NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `download_count` int(11) DEFAULT 0,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `type` enum('note','assignment','grade','general','announcement') DEFAULT 'general',
  `sent_by` int(11) NOT NULL,
  `target_role` enum('all','student','lecturer','admin') DEFAULT 'all',
  `course_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `title`, `message`, `type`, `sent_by`, `target_role`, `course_id`, `created_at`) VALUES
(1, 'Welcome to OnlineLMS', 'Welcome! The system is now live and ready to use.', 'announcement', 1, 'all', NULL, '2026-06-21 06:27:47'),
(2, 'New Notes Uploaded', 'New Python notes for Week 1 have been uploaded.', 'note', 2, 'student', NULL, '2026-06-21 06:27:47'),
(3, 'Assignment Due Soon', 'Python Calculator assignment is due in 7 days. Submit on time.', 'assignment', 2, 'student', NULL, '2026-06-21 06:27:47'),
(4, 'New Assignment Posted', 'A new assignment on Database Schema has been posted.', 'assignment', 2, 'student', NULL, '2026-06-21 06:27:47'),
(5, 'hgk,hlkj', 'vjhgoul', 'general', 1, 'all', NULL, '2026-06-21 20:08:50');

-- --------------------------------------------------------

--
-- Table structure for table `notification_reads`
--

CREATE TABLE `notification_reads` (
  `id` int(11) NOT NULL,
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `submissions`
--

CREATE TABLE `submissions` (
  `id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` varchar(50) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('submitted','graded','late') DEFAULT 'submitted'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','lecturer','student') NOT NULL DEFAULT 'student',
  `profile_picture` varchar(255) DEFAULT 'profile-default.png',
  `phone` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `profile_picture`, `phone`, `status`, `created_at`, `updated_at`) VALUES
(1, 'System Admin', 'admin@lms.com', '$2y$10$mE60g1NVSsxWqfgsh9stHe7YrTKKuyJzvk7Iy5BNqEWaWYb6LLFCm', 'admin', 'profile-default.png', '+255700000001', 'active', '2026-06-21 06:27:47', '2026-06-21 06:27:47'),
(2, 'Dr. John Mwenda', 'lecturer1@lms.com', '$2y$10$mE60g1NVSsxWqfgsh9stHe7YrTKKuyJzvk7Iy5BNqEWaWYb6LLFCm', 'lecturer', 'profile-default.png', '+255700000002', 'active', '2026-06-21 06:27:47', '2026-06-21 06:27:47'),
(4, 'Alice Moshi', 'student1@lms.com', '$2y$10$mE60g1NVSsxWqfgsh9stHe7YrTKKuyJzvk7Iy5BNqEWaWYb6LLFCm', 'student', 'profile-default.png', '+255700000004', 'active', '2026-06-21 06:27:47', '2026-06-21 06:27:47'),
(5, 'Bob Temba', 'student2@lms.com', '$2y$10$mE60g1NVSsxWqfgsh9stHe7YrTKKuyJzvk7Iy5BNqEWaWYb6LLFCm', 'student', 'profile-default.png', '+255700000005', 'active', '2026-06-21 06:27:47', '2026-06-21 06:27:47'),
(6, 'Carol Ndege', 'student3@lms.com', '$2y$10$mE60g1NVSsxWqfgsh9stHe7YrTKKuyJzvk7Iy5BNqEWaWYb6LLFCm', 'student', 'profile-default.png', '+255700000006', 'active', '2026-06-21 06:27:47', '2026-06-21 06:27:47'),
(7, 'Rich Maunyama', 'entry@sbi.co.tz', '$2y$10$eib8RzC76P6GOwRBvPPHWOXZ.RiT/exby74H/aSbmiPhNACFZcZXa', 'student', 'profile_1782071879_6a3842473a962.png', '0759596861', 'active', '2026-06-21 19:57:59', '2026-06-21 19:57:59'),
(8, 'Fatuma Shilagi', 'shilagifatuma@gmail.com', '$2y$10$RaL6Hmhn8i7iQqM28r9Fp.vnrTGCrOoVcYBxK4txpZxHYKaAEUXGy', 'lecturer', 'profile-default.png', '0699643093', 'active', '2026-06-21 20:18:34', '2026-06-21 20:18:34');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `course_code` (`course_code`),
  ADD KEY `lecturer_id` (`lecturer_id`);

--
-- Indexes for table `course_enrollments`
--
ALTER TABLE `course_enrollments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_enrollment` (`student_id`,`course_id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `grades`
--
ALTER TABLE `grades`
  ADD PRIMARY KEY (`id`),
  ADD KEY `submission_id` (`submission_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `assignment_id` (`assignment_id`),
  ADD KEY `graded_by` (`graded_by`);

--
-- Indexes for table `notes`
--
ALTER TABLE `notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sent_by` (`sent_by`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `notification_reads`
--
ALTER TABLE `notification_reads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_read` (`notification_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `submissions`
--
ALTER TABLE `submissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_submission` (`assignment_id`,`student_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `course_enrollments`
--
ALTER TABLE `course_enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `grades`
--
ALTER TABLE `grades`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notes`
--
ALTER TABLE `notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `notification_reads`
--
ALTER TABLE `notification_reads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `submissions`
--
ALTER TABLE `submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `assignments`
--
ALTER TABLE `assignments`
  ADD CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `assignments_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `courses`
--
ALTER TABLE `courses`
  ADD CONSTRAINT `courses_ibfk_1` FOREIGN KEY (`lecturer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `course_enrollments`
--
ALTER TABLE `course_enrollments`
  ADD CONSTRAINT `course_enrollments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `course_enrollments_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `grades`
--
ALTER TABLE `grades`
  ADD CONSTRAINT `grades_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `submissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `grades_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `grades_ibfk_3` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `grades_ibfk_4` FOREIGN KEY (`graded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notes`
--
ALTER TABLE `notes`
  ADD CONSTRAINT `notes_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notes_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notification_reads`
--
ALTER TABLE `notification_reads`
  ADD CONSTRAINT `notification_reads_ibfk_1` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notification_reads_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `submissions`
--
ALTER TABLE `submissions`
  ADD CONSTRAINT `submissions_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `submissions_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
