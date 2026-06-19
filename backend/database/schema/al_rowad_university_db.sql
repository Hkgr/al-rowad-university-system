-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 09, 2026 at 10:15 AM
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
-- Database: `al_rowad_university_db`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `accept_applicant_as_student` (IN `p_admission_application_id` INT, IN `p_student_number` VARCHAR(50), IN `p_current_academic_level_id` INT, IN `p_enrollment_date` DATE)   BEGIN
    DECLARE v_applicant_id INT;
    DECLARE v_program_id INT;
    DECLARE v_status_id INT;

    SELECT applicant_id, academic_program_id
      INTO v_applicant_id, v_program_id
    FROM admission_applications
    WHERE admission_application_id = p_admission_application_id
      AND decision_status = 'accepted';

    SELECT student_status_id INTO v_status_id FROM student_statuses WHERE status_code = 'active';

    INSERT INTO students
    (student_number, admission_application_id, first_name, last_name, father_name, mother_name, date_of_birth, gender, phone_number, email, address, nationality, academic_program_id, current_academic_level_id, enrollment_date, student_status_id)
    SELECT p_student_number, p_admission_application_id, first_name, last_name, father_name, mother_name, date_of_birth, gender, phone_number, email, address, nationality, v_program_id, p_current_academic_level_id, p_enrollment_date, v_status_id
    FROM applicants
    WHERE applicant_id = v_applicant_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `approve_grades` (IN `p_grade_approval_id` INT, IN `p_approved_by_user_id` INT, IN `p_approval_role` VARCHAR(100), IN `p_notes` TEXT)   BEGIN
    DECLARE v_approved_status_id INT;
    SELECT approval_status_id INTO v_approved_status_id FROM approval_statuses WHERE status_code = 'approved';

    UPDATE grade_approvals
    SET approval_status_id = v_approved_status_id,
        approved_by_user_id = p_approved_by_user_id,
        approval_role = p_approval_role,
        approval_date = NOW(),
        approval_notes = p_notes
    WHERE grade_approval_id = p_grade_approval_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `calculate_absence_percentage` (IN `p_student_id` INT, IN `p_course_offering_id` INT, OUT `p_absence_percentage` DECIMAL(5,2))   BEGIN
    SELECT ROUND(
        (SUM(CASE WHEN ats.counts_as_absent = TRUE THEN 1 ELSE 0 END) / NULLIF(COUNT(sa.student_attendance_id),0)) * 100, 2
    )
    INTO p_absence_percentage
    FROM student_attendance sa
    JOIN attendance_sessions ase ON ase.attendance_session_id = sa.attendance_session_id
    JOIN attendance_statuses ats ON ats.attendance_status_id = sa.attendance_status_id
    WHERE sa.student_id = p_student_id
      AND ase.course_offering_id = p_course_offering_id;

    SET p_absence_percentage = COALESCE(p_absence_percentage, 0);
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `calculate_final_grade` (IN `p_student_course_registration_id` INT, IN `p_calculated_by_user_id` INT)   BEGIN
    DECLARE v_theoretical DECIMAL(5,2) DEFAULT 0;
    DECLARE v_practical DECIMAL(5,2) DEFAULT 0;
    DECLARE v_coursework DECIMAL(5,2) DEFAULT 0;
    DECLARE v_final DECIMAL(5,2) DEFAULT 0;
    DECLARE v_status_code VARCHAR(50);
    DECLARE v_status_id INT;
    DECLARE v_deprived BOOLEAN DEFAULT FALSE;

    SELECT COALESCE(SUM(CASE WHEN gc.component_type = 'theoretical' THEN sgc.mark ELSE 0 END),0),
           COALESCE(SUM(CASE WHEN gc.component_type = 'practical' THEN sgc.mark ELSE 0 END),0),
           COALESCE(SUM(CASE WHEN gc.component_type = 'coursework' THEN sgc.mark ELSE 0 END),0)
      INTO v_theoretical, v_practical, v_coursework
    FROM student_grade_components sgc
    JOIN grade_components gc ON gc.grade_component_id = sgc.grade_component_id
    WHERE sgc.student_course_registration_id = p_student_course_registration_id;

    SET v_final = v_theoretical + v_practical + v_coursework;

    SELECT COALESCE(is_deprived, FALSE)
      INTO v_deprived
    FROM student_course_results
    WHERE student_course_registration_id = p_student_course_registration_id
    LIMIT 1;

    IF v_deprived THEN
        SET v_status_code = 'deprived';
    ELSEIF v_theoretical >= 15 AND v_practical >= 10 AND v_final >= 50 THEN
        SET v_status_code = 'passed';
    ELSE
        SET v_status_code = 'failed';
    END IF;

    SELECT result_status_id INTO v_status_id FROM result_statuses WHERE status_code = v_status_code;

    INSERT INTO student_course_results
    (student_course_registration_id, theoretical_total, practical_total, coursework_total, final_mark, result_status_id, is_deprived, calculated_at, calculated_by_user_id)
    VALUES
    (p_student_course_registration_id, v_theoretical, v_practical, v_coursework, v_final, v_status_id, v_deprived, NOW(), p_calculated_by_user_id)
    ON DUPLICATE KEY UPDATE
      theoretical_total = v_theoretical,
      practical_total = v_practical,
      coursework_total = v_coursework,
      final_mark = v_final,
      result_status_id = v_status_id,
      calculated_at = NOW(),
      calculated_by_user_id = p_calculated_by_user_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `check_course_prerequisites` (IN `p_student_id` INT, IN `p_course_id` INT, OUT `p_missing_count` INT)   BEGIN
    SELECT COUNT(*)
      INTO p_missing_count
    FROM course_prerequisites cp
    WHERE cp.course_id = p_course_id
      AND NOT EXISTS (
          SELECT 1
          FROM student_course_registrations scr
          JOIN course_offerings co ON co.course_offering_id = scr.course_offering_id
          JOIN student_course_results res ON res.student_course_registration_id = scr.student_course_registration_id
          JOIN result_statuses rst ON rst.result_status_id = res.result_status_id
          WHERE scr.student_id = p_student_id
            AND co.course_id = cp.prerequisite_course_id
            AND rst.status_code = 'passed'
      );
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `check_student_credit_limit` (IN `p_student_id` INT, IN `p_academic_year_id` INT, IN `p_semester_id` INT, OUT `p_current_hours` INT, OUT `p_max_hours` INT, OUT `p_is_allowed` BOOLEAN)   BEGIN
    SELECT COALESCE(SUM(c.credit_hours), 0)
      INTO p_current_hours
    FROM student_course_registrations scr
    JOIN course_offerings co ON co.course_offering_id = scr.course_offering_id
    JOIN courses c ON c.course_id = co.course_id
    JOIN registration_statuses rs ON rs.registration_status_id = scr.registration_status_id
    WHERE scr.student_id = p_student_id
      AND co.academic_year_id = p_academic_year_id
      AND co.semester_id = p_semester_id
      AND rs.status_code = 'registered';

    SELECT COALESCE(MAX(max_credit_hours), 18)
      INTO p_max_hours
    FROM student_credit_limits
    WHERE student_id = p_student_id
      AND academic_year_id = p_academic_year_id
      AND semester_id = p_semester_id;

    SET p_is_allowed = (p_current_hours <= p_max_hours);
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `check_user_permission` (IN `p_user_id` INT, IN `p_permission_code` VARCHAR(120), OUT `p_has_permission` BOOLEAN)   BEGIN
    SELECT EXISTS(
        SELECT 1
        FROM user_roles ur
        JOIN role_permissions rp ON rp.role_id = ur.role_id
        JOIN permissions p ON p.permission_id = rp.permission_id
        WHERE ur.user_id = p_user_id
          AND ur.is_active = TRUE
          AND p.permission_code = p_permission_code
          AND p.is_active = TRUE
    ) INTO p_has_permission;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `get_user_permissions` (IN `p_user_id` INT)   BEGIN
    SELECT DISTINCT p.permission_code, p.permission_name, sm.module_code, sm.module_name
    FROM user_roles ur
    JOIN role_permissions rp ON rp.role_id = ur.role_id
    JOIN permissions p ON p.permission_id = rp.permission_id
    JOIN system_modules sm ON sm.module_id = p.module_id
    WHERE ur.user_id = p_user_id
      AND ur.is_active = TRUE
      AND p.is_active = TRUE
    ORDER BY sm.module_code, p.permission_code;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `mark_deprived_students` (IN `p_course_offering_id` INT)   BEGIN
    DECLARE v_deprived_status_id INT;

    SELECT result_status_id INTO v_deprived_status_id
    FROM result_statuses WHERE status_code = 'deprived';

    INSERT INTO student_course_results
    (student_course_registration_id, theoretical_total, practical_total, coursework_total, final_mark, result_status_id, is_deprived, calculated_at)
    SELECT scr.student_course_registration_id, 0, 0, 0, 0, v_deprived_status_id, TRUE, NOW()
    FROM student_course_registrations scr
    JOIN vw_attendance_percentage vap ON vap.student_id = scr.student_id AND vap.course_offering_id = scr.course_offering_id
    WHERE scr.course_offering_id = p_course_offering_id
      AND vap.absence_percentage > 15
    ON DUPLICATE KEY UPDATE
      result_status_id = v_deprived_status_id,
      is_deprived = TRUE,
      calculated_at = NOW();
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `register_student_course` (IN `p_student_id` INT, IN `p_course_offering_id` INT, IN `p_registered_by_user_id` INT, IN `p_advisor_user_id` INT)   BEGIN
    DECLARE v_course_id INT;
    DECLARE v_year_id INT;
    DECLARE v_semester_id INT;
    DECLARE v_current_hours INT DEFAULT 0;
    DECLARE v_max_hours INT DEFAULT 18;
    DECLARE v_new_course_hours INT DEFAULT 0;
    DECLARE v_missing_prereq INT DEFAULT 0;
    DECLARE v_registered_status_id INT;
    DECLARE v_allowed BOOLEAN DEFAULT FALSE;

    SELECT course_id, academic_year_id, semester_id
      INTO v_course_id, v_year_id, v_semester_id
    FROM course_offerings
    WHERE course_offering_id = p_course_offering_id AND status = 'open';

    CALL check_course_prerequisites(p_student_id, v_course_id, v_missing_prereq);
    IF v_missing_prereq > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Student has missing prerequisites.';
    END IF;

    CALL check_student_credit_limit(p_student_id, v_year_id, v_semester_id, v_current_hours, v_max_hours, v_allowed);
    SELECT credit_hours INTO v_new_course_hours FROM courses WHERE course_id = v_course_id;

    IF (v_current_hours + v_new_course_hours) > v_max_hours THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Credit hour limit exceeded.';
    END IF;

    SELECT registration_status_id INTO v_registered_status_id
    FROM registration_statuses WHERE status_code = 'registered';

    INSERT INTO student_course_registrations
    (student_id, course_offering_id, registration_date, registered_by_user_id, advisor_user_id, registration_status_id)
    VALUES
    (p_student_id, p_course_offering_id, CURRENT_DATE, p_registered_by_user_id, p_advisor_user_id, v_registered_status_id);

    UPDATE course_offerings
    SET available_seats = CASE WHEN available_seats > 0 THEN available_seats - 1 ELSE 0 END
    WHERE course_offering_id = p_course_offering_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `reject_grades` (IN `p_grade_approval_id` INT, IN `p_rejected_by_user_id` INT, IN `p_notes` TEXT)   BEGIN
    DECLARE v_rejected_status_id INT;
    SELECT approval_status_id INTO v_rejected_status_id FROM approval_statuses WHERE status_code = 'rejected';

    UPDATE grade_approvals
    SET approval_status_id = v_rejected_status_id,
        approved_by_user_id = p_rejected_by_user_id,
        approval_date = NOW(),
        approval_notes = p_notes
    WHERE grade_approval_id = p_grade_approval_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `review_grade_appeal` (IN `p_grade_appeal_id` INT, IN `p_status_code` VARCHAR(50), IN `p_reviewed_by_user_id` INT, IN `p_review_notes` TEXT)   BEGIN
    DECLARE v_status_id INT;
    SELECT appeal_status_id INTO v_status_id FROM appeal_statuses WHERE status_code = p_status_code;

    UPDATE grade_appeals
    SET appeal_status_id = v_status_id,
        reviewed_by_user_id = p_reviewed_by_user_id,
        review_notes = p_review_notes,
        decision_date = NOW()
    WHERE grade_appeal_id = p_grade_appeal_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `submit_grades_for_approval` (IN `p_course_offering_id` INT, IN `p_submitted_by_user_id` INT)   BEGIN
    DECLARE v_pending_status_id INT;
    SELECT approval_status_id INTO v_pending_status_id FROM approval_statuses WHERE status_code = 'pending';

    INSERT INTO grade_approvals (course_offering_id, approval_status_id, submitted_by_user_id, submitted_at)
    VALUES (p_course_offering_id, v_pending_status_id, p_submitted_by_user_id, NOW());
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `submit_grade_appeal` (IN `p_student_id` INT, IN `p_student_course_registration_id` INT, IN `p_appeal_reason` TEXT)   BEGIN
    DECLARE v_status_id INT;
    SELECT appeal_status_id INTO v_status_id FROM appeal_statuses WHERE status_code = 'submitted';

    INSERT INTO grade_appeals
    (student_id, student_course_registration_id, appeal_reason, appeal_status_id, submitted_at)
    VALUES
    (p_student_id, p_student_course_registration_id, p_appeal_reason, v_status_id, NOW());
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `update_grade_with_audit` (IN `p_student_grade_component_id` INT, IN `p_new_mark` DECIMAL(5,2), IN `p_changed_by_user_id` INT, IN `p_change_reason` TEXT)   BEGIN
    DECLARE v_old_mark DECIMAL(5,2);
    SELECT mark INTO v_old_mark
    FROM student_grade_components
    WHERE student_grade_component_id = p_student_grade_component_id;

    INSERT INTO grade_audit_logs
    (student_grade_component_id, old_mark, new_mark, changed_by_user_id, change_reason)
    VALUES
    (p_student_grade_component_id, v_old_mark, p_new_mark, p_changed_by_user_id, p_change_reason);

    UPDATE student_grade_components
    SET mark = p_new_mark,
        grade_status = 'corrected',
        updated_at = CURRENT_TIMESTAMP
    WHERE student_grade_component_id = p_student_grade_component_id;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `academic_levels`
--

CREATE TABLE `academic_levels` (
  `academic_level_id` int(11) NOT NULL,
  `level_code` varchar(50) NOT NULL,
  `level_name` varchar(100) NOT NULL,
  `level_order` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `academic_levels`
--

INSERT INTO `academic_levels` (`academic_level_id`, `level_code`, `level_name`, `level_order`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'year_1', 'Year 1', 1, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(2, 'year_2', 'Year 2', 2, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(3, 'year_3', 'Year 3', 3, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(4, 'year_4', 'Year 4', 4, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(5, 'year_5', 'Year 5', 5, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56');

-- --------------------------------------------------------

--
-- Table structure for table `academic_programs`
--

CREATE TABLE `academic_programs` (
  `academic_program_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `program_code` varchar(50) NOT NULL,
  `program_name` varchar(200) NOT NULL,
  `degree_level` varchar(80) NOT NULL DEFAULT 'Bachelor',
  `total_credit_hours` int(11) NOT NULL DEFAULT 120,
  `duration_years` int(11) NOT NULL DEFAULT 4,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `academic_programs`
--

INSERT INTO `academic_programs` (`academic_program_id`, `department_id`, `program_code`, `program_name`, `degree_level`, `total_credit_hours`, `duration_years`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 'BSC_STAT', 'Bachelor of Statistics', 'Bachelor', 132, 4, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `academic_years`
--

CREATE TABLE `academic_years` (
  `academic_year_id` int(11) NOT NULL,
  `year_name` varchar(50) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `academic_years`
--

INSERT INTO `academic_years` (`academic_year_id`, `year_name`, `start_date`, `end_date`, `is_current`, `is_active`, `created_at`, `updated_at`) VALUES
(1, '2025-2026', '2025-09-01', '2026-08-31', 1, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `account_statuses`
--

CREATE TABLE `account_statuses` (
  `account_status_id` int(11) NOT NULL,
  `status_code` varchar(50) NOT NULL,
  `status_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `account_statuses`
--

INSERT INTO `account_statuses` (`account_status_id`, `status_code`, `status_name`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'active', 'Active', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(2, 'disabled', 'Disabled', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(3, 'locked', 'Locked', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(4, 'pending', 'Pending', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56');

-- --------------------------------------------------------

--
-- Table structure for table `admission_applications`
--

CREATE TABLE `admission_applications` (
  `admission_application_id` int(11) NOT NULL,
  `applicant_id` int(11) NOT NULL,
  `academic_program_id` int(11) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `application_date` date NOT NULL,
  `decision_status` varchar(50) NOT NULL DEFAULT 'pending',
  `decision_date` date DEFAULT NULL,
  `decided_by_user_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `admission_applications`
--

INSERT INTO `admission_applications` (`admission_application_id`, `applicant_id`, `academic_program_id`, `academic_year_id`, `application_date`, `decision_status`, `decision_date`, `decided_by_user_id`, `notes`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, '2025-08-01', 'accepted', '2025-08-15', 2, NULL, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `appeal_statuses`
--

CREATE TABLE `appeal_statuses` (
  `appeal_status_id` int(11) NOT NULL,
  `status_code` varchar(50) NOT NULL,
  `status_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `appeal_statuses`
--

INSERT INTO `appeal_statuses` (`appeal_status_id`, `status_code`, `status_name`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'submitted', 'Submitted', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 'under_review', 'Under Review', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(3, 'accepted', 'Accepted', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(4, 'rejected', 'Rejected', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(5, 'closed', 'Closed', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `applicants`
--

CREATE TABLE `applicants` (
  `applicant_id` int(11) NOT NULL,
  `applicant_number` varchar(50) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `father_name` varchar(100) DEFAULT NULL,
  `mother_name` varchar(100) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `phone_number` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `nationality` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `applicants`
--

INSERT INTO `applicants` (`applicant_id`, `applicant_number`, `first_name`, `last_name`, `father_name`, `mother_name`, `date_of_birth`, `gender`, `phone_number`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES
(1, 'APP-2026-001', 'Mutaz', 'Alabdullah', 'Mahmoud', 'Aisha', '2001-05-10', 'male', '+963900000010', 'mutaz.student@example.com', 'Aleppo', 'Syrian', '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `approval_statuses`
--

CREATE TABLE `approval_statuses` (
  `approval_status_id` int(11) NOT NULL,
  `status_code` varchar(50) NOT NULL,
  `status_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `approval_statuses`
--

INSERT INTO `approval_statuses` (`approval_status_id`, `status_code`, `status_name`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'pending', 'Pending', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 'approved', 'Approved', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(3, 'rejected', 'Rejected', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(4, 'returned_for_correction', 'Returned for Correction', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `attendance_sessions`
--

CREATE TABLE `attendance_sessions` (
  `attendance_session_id` int(11) NOT NULL,
  `course_offering_id` int(11) NOT NULL,
  `session_type` varchar(50) NOT NULL,
  `session_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `faculty_member_id` int(11) DEFAULT NULL,
  `created_by_user_id` int(11) NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `attendance_sessions`
--

INSERT INTO `attendance_sessions` (`attendance_session_id`, `course_offering_id`, `session_type`, `session_date`, `start_time`, `end_time`, `faculty_member_id`, `created_by_user_id`, `notes`, `created_at`, `updated_at`) VALUES
(1, 1, 'theoretical', '2025-09-10', '09:00:00', '10:30:00', 1, 3, NULL, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 1, 'practical', '2025-09-12', '11:00:00', '12:30:00', 1, 3, NULL, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `attendance_statuses`
--

CREATE TABLE `attendance_statuses` (
  `attendance_status_id` int(11) NOT NULL,
  `status_code` varchar(50) NOT NULL,
  `status_name` varchar(100) NOT NULL,
  `counts_as_absent` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `attendance_statuses`
--

INSERT INTO `attendance_statuses` (`attendance_status_id`, `status_code`, `status_name`, `counts_as_absent`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'present', 'Present', 0, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 'absent', 'Absent', 1, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(3, 'excused', 'Excused', 0, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(4, 'late', 'Late', 0, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `boards`
--

CREATE TABLE `boards` (
  `board_id` int(11) NOT NULL,
  `board_code` varchar(50) NOT NULL,
  `board_name` varchar(150) NOT NULL,
  `organizational_unit_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `boards`
--

INSERT INTO `boards` (`board_id`, `board_code`, `board_name`, `organizational_unit_id`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'BOT', 'Board of Trustees', 1, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 'UC', 'University Council', 2, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `board_decisions`
--

CREATE TABLE `board_decisions` (
  `board_decision_id` int(11) NOT NULL,
  `board_meeting_id` int(11) NOT NULL,
  `decision_number` varchar(80) DEFAULT NULL,
  `decision_title` varchar(200) NOT NULL,
  `decision_text` text NOT NULL,
  `decision_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `board_decisions`
--

INSERT INTO `board_decisions` (`board_decision_id`, `board_meeting_id`, `decision_number`, `decision_title`, `decision_text`, `decision_date`, `created_at`, `updated_at`) VALUES
(1, 1, 'DEC-2025-001', 'Approve Academic Calendar', 'The board approved the academic calendar for 2025-2026.', '2025-09-15', '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `board_decision_attachments`
--

CREATE TABLE `board_decision_attachments` (
  `attachment_id` int(11) NOT NULL,
  `board_decision_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_url` varchar(500) NOT NULL,
  `uploaded_by_user_id` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `board_meetings`
--

CREATE TABLE `board_meetings` (
  `board_meeting_id` int(11) NOT NULL,
  `board_id` int(11) NOT NULL,
  `meeting_title` varchar(200) NOT NULL,
  `meeting_date` datetime NOT NULL,
  `location` varchar(200) DEFAULT NULL,
  `agenda` text DEFAULT NULL,
  `minutes` text DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `board_meetings`
--

INSERT INTO `board_meetings` (`board_meeting_id`, `board_id`, `meeting_title`, `meeting_date`, `location`, `agenda`, `minutes`, `created_by_user_id`, `created_at`, `updated_at`) VALUES
(1, 1, 'First Board Meeting', '2025-09-15 10:00:00', 'Main Hall', 'Approve academic year plan', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `board_members`
--

CREATE TABLE `board_members` (
  `board_member_id` int(11) NOT NULL,
  `board_id` int(11) NOT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `full_name` varchar(200) NOT NULL,
  `member_title` varchar(150) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `board_members`
--

INSERT INTO `board_members` (`board_member_id`, `board_id`, `employee_id`, `full_name`, `member_title`, `start_date`, `end_date`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, NULL, 'Dr. Omar Al Rowad', 'Chairman', '2025-01-01', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `colleges`
--

CREATE TABLE `colleges` (
  `college_id` int(11) NOT NULL,
  `organizational_unit_id` int(11) DEFAULT NULL,
  `college_code` varchar(50) NOT NULL,
  `college_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `colleges`
--

INSERT INTO `colleges` (`college_id`, `organizational_unit_id`, `college_code`, `college_name`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 11, 'ECON', 'College of Economics', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `course_id` int(11) NOT NULL,
  `course_code` varchar(50) NOT NULL,
  `course_name` varchar(200) NOT NULL,
  `credit_hours` int(11) NOT NULL,
  `theoretical_hours` int(11) DEFAULT 0,
  `practical_hours` int(11) DEFAULT 0,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `courses`
--

INSERT INTO `courses` (`course_id`, `course_code`, `course_name`, `credit_hours`, `theoretical_hours`, `practical_hours`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'STAT101', 'Introduction to Statistics', 3, 2, 2, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 'STAT102', 'Probability Theory', 3, 2, 2, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(3, 'STAT201', 'Statistical Inference', 3, 2, 2, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `course_departments`
--

CREATE TABLE `course_departments` (
  `course_department_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `course_departments`
--

INSERT INTO `course_departments` (`course_department_id`, `course_id`, `department_id`, `is_primary`, `created_at`) VALUES
(1, 1, 1, 1, '2026-05-24 12:41:57'),
(2, 2, 1, 1, '2026-05-24 12:41:57'),
(3, 3, 1, 1, '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `course_instructors`
--

CREATE TABLE `course_instructors` (
  `course_instructor_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `faculty_member_id` int(11) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `course_instructors`
--

INSERT INTO `course_instructors` (`course_instructor_id`, `course_id`, `faculty_member_id`, `is_primary`, `is_active`, `created_at`) VALUES
(1, 1, 1, 1, 1, '2026-05-24 12:41:57'),
(2, 2, 1, 1, 1, '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `course_offerings`
--

CREATE TABLE `course_offerings` (
  `course_offering_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `academic_program_id` int(11) DEFAULT NULL,
  `faculty_member_id` int(11) DEFAULT NULL,
  `capacity` int(11) NOT NULL DEFAULT 0,
  `available_seats` int(11) NOT NULL DEFAULT 0,
  `status` varchar(50) NOT NULL DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `course_offerings`
--

INSERT INTO `course_offerings` (`course_offering_id`, `course_id`, `academic_year_id`, `semester_id`, `department_id`, `academic_program_id`, `faculty_member_id`, `capacity`, `available_seats`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, 1, 1, 1, 50, 49, 'open', '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `course_prerequisites`
--

CREATE TABLE `course_prerequisites` (
  `course_prerequisite_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `prerequisite_course_id` int(11) NOT NULL,
  `minimum_result_status_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `course_prerequisites`
--

INSERT INTO `course_prerequisites` (`course_prerequisite_id`, `course_id`, `prerequisite_course_id`, `minimum_result_status_id`, `created_at`) VALUES
(1, 3, 2, 1, '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL,
  `college_id` int(11) NOT NULL,
  `organizational_unit_id` int(11) DEFAULT NULL,
  `department_code` varchar(50) NOT NULL,
  `department_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`department_id`, `college_id`, `organizational_unit_id`, `department_code`, `department_name`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 12, 'STAT', 'Department of Statistics', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `document_types`
--

CREATE TABLE `document_types` (
  `document_type_id` int(11) NOT NULL,
  `type_code` varchar(50) NOT NULL,
  `type_name` varchar(150) NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `document_types`
--

INSERT INTO `document_types` (`document_type_id`, `type_code`, `type_name`, `is_required`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'national_id', 'National ID Image', 1, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 'high_school_certificate', 'High School Certificate', 1, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(3, 'personal_photo', 'Personal Photo', 1, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(4, 'passport_copy', 'Passport Copy', 0, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `employee_id` int(11) NOT NULL,
  `employee_number` varchar(50) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `father_name` varchar(100) DEFAULT NULL,
  `mother_name` varchar(100) DEFAULT NULL,
  `phone_number` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `employee_type_id` int(11) NOT NULL,
  `employee_status_id` int(11) NOT NULL,
  `organizational_unit_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`employee_id`, `employee_number`, `first_name`, `last_name`, `father_name`, `mother_name`, `phone_number`, `email`, `hire_date`, `employee_type_id`, `employee_status_id`, `organizational_unit_id`, `created_at`, `updated_at`) VALUES
(1, 'EMP-001', 'Ahmad', 'Khaled', 'Khaled', 'Mariam', '+963900000001', 'ahmad.khaled@rowad.edu', '2023-09-01', 1, 1, 12, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 'EMP-002', 'Sara', 'Hassan', 'Hassan', 'Nadia', '+963900000002', 'sara.hassan@rowad.edu', '2023-09-01', 2, 1, 7, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `employee_positions`
--

CREATE TABLE `employee_positions` (
  `employee_position_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `position_id` int(11) NOT NULL,
  `organizational_unit_id` int(11) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `employee_statuses`
--

CREATE TABLE `employee_statuses` (
  `employee_status_id` int(11) NOT NULL,
  `status_code` varchar(50) NOT NULL,
  `status_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `employee_statuses`
--

INSERT INTO `employee_statuses` (`employee_status_id`, `status_code`, `status_name`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'active', 'Active', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(2, 'inactive', 'Inactive', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(3, 'on_leave', 'On Leave', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(4, 'terminated', 'Terminated', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56');

-- --------------------------------------------------------

--
-- Table structure for table `employee_types`
--

CREATE TABLE `employee_types` (
  `employee_type_id` int(11) NOT NULL,
  `type_code` varchar(50) NOT NULL,
  `type_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `employee_types`
--

INSERT INTO `employee_types` (`employee_type_id`, `type_code`, `type_name`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'academic', 'Academic', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(2, 'administrative', 'Administrative', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(3, 'technical', 'Technical', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(4, 'service', 'Service', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(5, 'board_member', 'Board Member', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56');

-- --------------------------------------------------------

--
-- Table structure for table `employee_unit_assignments`
--

CREATE TABLE `employee_unit_assignments` (
  `assignment_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `organizational_unit_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `assignment_notes` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `faculty_members`
--

CREATE TABLE `faculty_members` (
  `faculty_member_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `academic_rank` varchar(100) DEFAULT NULL,
  `specialization` varchar(200) DEFAULT NULL,
  `office_location` varchar(150) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `faculty_members`
--

INSERT INTO `faculty_members` (`faculty_member_id`, `employee_id`, `academic_rank`, `specialization`, `office_location`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 'Assistant Professor', 'Applied Statistics', 'B-201', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `grade_appeals`
--

CREATE TABLE `grade_appeals` (
  `grade_appeal_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `student_course_registration_id` int(11) NOT NULL,
  `appeal_reason` text NOT NULL,
  `appeal_status_id` int(11) NOT NULL,
  `submitted_at` datetime NOT NULL,
  `reviewed_by_user_id` int(11) DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `decision_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `grade_approvals`
--

CREATE TABLE `grade_approvals` (
  `grade_approval_id` int(11) NOT NULL,
  `course_offering_id` int(11) NOT NULL,
  `approval_status_id` int(11) NOT NULL,
  `submitted_by_user_id` int(11) NOT NULL,
  `submitted_at` datetime NOT NULL,
  `approved_by_user_id` int(11) DEFAULT NULL,
  `approval_role` varchar(100) DEFAULT NULL,
  `approval_date` datetime DEFAULT NULL,
  `approval_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `grade_audit_logs`
--

CREATE TABLE `grade_audit_logs` (
  `grade_audit_log_id` bigint(20) NOT NULL,
  `student_grade_component_id` int(11) NOT NULL,
  `old_mark` decimal(5,2) DEFAULT NULL,
  `new_mark` decimal(5,2) DEFAULT NULL,
  `changed_by_user_id` int(11) NOT NULL,
  `change_reason` text NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `grade_components`
--

CREATE TABLE `grade_components` (
  `grade_component_id` int(11) NOT NULL,
  `course_offering_id` int(11) NOT NULL,
  `component_name` varchar(150) NOT NULL,
  `component_type` varchar(50) NOT NULL COMMENT 'theoretical, practical, coursework',
  `max_mark` decimal(5,2) NOT NULL,
  `weight_percentage` decimal(5,2) DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `exam_date` date DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'scheduled',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `grade_components`
--

INSERT INTO `grade_components` (`grade_component_id`, `course_offering_id`, `component_name`, `component_type`, `max_mark`, `weight_percentage`, `is_required`, `exam_date`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'Midterm Exam', 'theoretical', 20.00, 20.00, 1, '2025-11-01', 'scheduled', '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 1, 'Final Theoretical Exam', 'theoretical', 40.00, 40.00, 1, '2026-01-10', 'scheduled', '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(3, 1, 'Practical Exam', 'practical', 40.00, 40.00, 1, '2026-01-05', 'scheduled', '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `grading_policies`
--

CREATE TABLE `grading_policies` (
  `grading_policy_id` int(11) NOT NULL,
  `policy_name` varchar(150) NOT NULL,
  `theoretical_max_mark` decimal(5,2) NOT NULL DEFAULT 60.00,
  `practical_max_mark` decimal(5,2) NOT NULL DEFAULT 40.00,
  `minimum_theoretical_mark` decimal(5,2) NOT NULL DEFAULT 15.00,
  `minimum_practical_mark` decimal(5,2) NOT NULL DEFAULT 10.00,
  `minimum_final_mark` decimal(5,2) NOT NULL DEFAULT 50.00,
  `absence_deprivation_percentage` decimal(5,2) NOT NULL DEFAULT 15.00,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `grading_policies`
--

INSERT INTO `grading_policies` (`grading_policy_id`, `policy_name`, `theoretical_max_mark`, `practical_max_mark`, `minimum_theoretical_mark`, `minimum_practical_mark`, `minimum_final_mark`, `absence_deprivation_percentage`, `is_default`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Default Al Rowad Grading Policy', 60.00, 40.00, 15.00, 10.00, 50.00, 15.00, 1, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `library_authors`
--

CREATE TABLE `library_authors` (
  `library_author_id` int(11) NOT NULL,
  `author_name` varchar(200) NOT NULL,
  `biography` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `library_authors`
--

INSERT INTO `library_authors` (`library_author_id`, `author_name`, `biography`, `created_at`, `updated_at`) VALUES
(1, 'Douglas C. Montgomery', NULL, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 'Sheldon Ross', NULL, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `library_books`
--

CREATE TABLE `library_books` (
  `library_book_id` int(11) NOT NULL,
  `isbn` varchar(50) DEFAULT NULL,
  `title` varchar(250) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `publisher` varchar(200) DEFAULT NULL,
  `publication_year` int(11) DEFAULT NULL,
  `language` varchar(80) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `library_books`
--

INSERT INTO `library_books` (`library_book_id`, `isbn`, `title`, `category_id`, `publisher`, `publication_year`, `language`, `created_at`, `updated_at`) VALUES
(1, '9781119113478', 'Introduction to Statistical Quality Control', 1, 'Wiley', 2019, 'English', '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `library_book_authors`
--

CREATE TABLE `library_book_authors` (
  `book_author_id` int(11) NOT NULL,
  `library_book_id` int(11) NOT NULL,
  `library_author_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `library_book_authors`
--

INSERT INTO `library_book_authors` (`book_author_id`, `library_book_id`, `library_author_id`) VALUES
(1, 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `library_book_copies`
--

CREATE TABLE `library_book_copies` (
  `library_book_copy_id` int(11) NOT NULL,
  `library_book_id` int(11) NOT NULL,
  `copy_barcode` varchar(80) NOT NULL,
  `copy_status` varchar(50) NOT NULL DEFAULT 'available',
  `shelf_location` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `library_book_copies`
--

INSERT INTO `library_book_copies` (`library_book_copy_id`, `library_book_id`, `copy_barcode`, `copy_status`, `shelf_location`, `created_at`, `updated_at`) VALUES
(1, 1, 'LIB-STAT-0001', 'available', 'S1-A2', '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `library_borrowings`
--

CREATE TABLE `library_borrowings` (
  `library_borrowing_id` int(11) NOT NULL,
  `library_book_copy_id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `borrowed_at` datetime NOT NULL,
  `due_at` datetime NOT NULL,
  `returned_at` datetime DEFAULT NULL,
  `borrowing_status` varchar(50) NOT NULL DEFAULT 'borrowed',
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `library_categories`
--

CREATE TABLE `library_categories` (
  `library_category_id` int(11) NOT NULL,
  `category_name` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `library_categories`
--

INSERT INTO `library_categories` (`library_category_id`, `category_name`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Statistics', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 'Computer Science', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(3, 'Management', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `login_audit_logs`
--

CREATE TABLE `login_audit_logs` (
  `login_audit_id` bigint(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username_attempted` varchar(100) DEFAULT NULL,
  `login_status` varchar(50) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `meeting_attendees`
--

CREATE TABLE `meeting_attendees` (
  `meeting_attendee_id` int(11) NOT NULL,
  `board_meeting_id` int(11) NOT NULL,
  `board_member_id` int(11) NOT NULL,
  `attendance_status` varchar(50) NOT NULL DEFAULT 'present',
  `notes` varchar(255) DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `organizational_units`
--

CREATE TABLE `organizational_units` (
  `organizational_unit_id` int(11) NOT NULL,
  `unit_code` varchar(50) DEFAULT NULL,
  `unit_name` varchar(200) NOT NULL,
  `unit_type_id` int(11) NOT NULL,
  `parent_unit_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `organizational_units`
--

INSERT INTO `organizational_units` (`organizational_unit_id`, `unit_code`, `unit_name`, `unit_type_id`, `parent_unit_id`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'BOT', 'Board of Trustees', 1, NULL, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 'UC', 'University Council', 2, NULL, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(3, 'PRES', 'University President', 3, NULL, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(4, 'VP_ADMIN', 'Vice President for Administrative Affairs', 4, 3, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(5, 'VP_SCI', 'Vice President for Scientific Affairs', 4, 3, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(6, 'VP_COMM', 'Vice President for Community Affairs', 4, 3, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(7, 'REG_OFFICE', 'Admissions and Registration Office', 7, 4, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(8, 'EXAM_OFFICE', 'Exam Office', 7, 4, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(9, 'HR_OFFICE', 'HR Office', 7, 4, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(10, 'LIBRARY', 'University Library', 8, 4, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(11, 'ECON_COL', 'College of Economics', 10, 5, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(12, 'STAT_DEPT', 'Department of Statistics', 11, 11, NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `organizational_unit_types`
--

CREATE TABLE `organizational_unit_types` (
  `unit_type_id` int(11) NOT NULL,
  `type_code` varchar(50) NOT NULL,
  `type_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `organizational_unit_types`
--

INSERT INTO `organizational_unit_types` (`unit_type_id`, `type_code`, `type_name`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'board', 'Board', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(2, 'council', 'Council', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(3, 'presidency', 'Presidency', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(4, 'vice_presidency', 'Vice Presidency', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(5, 'administration', 'Administration', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(6, 'directorate', 'Directorate', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(7, 'office', 'Office', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(8, 'center', 'Center', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(9, 'club', 'Club', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(10, 'college', 'College', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(11, 'department', 'Department', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(12, 'institute', 'Institute', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(13, 'lab', 'Lab', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(14, 'committee', 'Committee', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(15, 'unit', 'Unit', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56');

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `token_id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `permission_id` int(11) NOT NULL,
  `module_id` int(11) NOT NULL,
  `permission_code` varchar(120) NOT NULL,
  `permission_name` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`permission_id`, `module_id`, `permission_code`, `permission_name`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 'students.view', 'Students View', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 2, 'admissions.view', 'Admissions View', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(3, 3, 'academic_structure.view', 'Academic Structure View', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(4, 4, 'courses.view', 'Courses View', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(5, 5, 'registration.view', 'Registration View', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(6, 6, 'exams.view', 'Exams View', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(7, 7, 'grades.view', 'Grades View', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(8, 8, 'attendance.view', 'Attendance View', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(9, 9, 'hr.view', 'Human Resources View', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(10, 10, 'library.view', 'Library View', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(11, 11, 'reports.view', 'Reports View', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(12, 12, 'dashboards.view', 'Dashboards View', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(13, 13, 'boards.view', 'Boards View', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(14, 14, 'organizational_structure.view', 'Organizational Structure View', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(15, 15, 'system_settings.view', 'System Settings View', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(16, 16, 'users_permissions.view', 'Users and Permissions View', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(32, 1, 'students.manage', 'Students Manage', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(33, 2, 'admissions.manage', 'Admissions Manage', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(34, 3, 'academic_structure.manage', 'Academic Structure Manage', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(35, 4, 'courses.manage', 'Courses Manage', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(36, 5, 'registration.manage', 'Registration Manage', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(37, 6, 'exams.manage', 'Exams Manage', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(38, 7, 'grades.manage', 'Grades Manage', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(39, 8, 'attendance.manage', 'Attendance Manage', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(40, 9, 'hr.manage', 'Human Resources Manage', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(41, 10, 'library.manage', 'Library Manage', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(42, 11, 'reports.manage', 'Reports Manage', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(43, 12, 'dashboards.manage', 'Dashboards Manage', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(44, 13, 'boards.manage', 'Boards Manage', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(45, 14, 'organizational_structure.manage', 'Organizational Structure Manage', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(46, 15, 'system_settings.manage', 'System Settings Manage', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(47, 16, 'users_permissions.manage', 'Users and Permissions Manage', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `positions`
--

CREATE TABLE `positions` (
  `position_id` int(11) NOT NULL,
  `position_code` varchar(50) NOT NULL,
  `position_title` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `positions`
--

INSERT INTO `positions` (`position_id`, `position_code`, `position_title`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'PRESIDENT', 'University President', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 'VICE_PRESIDENT', 'Vice President', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(3, 'SECRETARY_GENERAL', 'University Secretary General', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(4, 'DEAN', 'Dean', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(5, 'HEAD_DEPARTMENT', 'Head of Department', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(6, 'INSTRUCTOR', 'Doctor / Instructor', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(7, 'ACADEMIC_ADVISOR', 'Academic Advisor', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(8, 'REGISTRATION_OFFICER', 'Registration Officer', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(9, 'EXAM_OFFICER', 'Exam Officer', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(10, 'HR_OFFICER', 'HR Officer', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(11, 'LIBRARIAN', 'Librarian', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `program_courses`
--

CREATE TABLE `program_courses` (
  `program_course_id` int(11) NOT NULL,
  `academic_program_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `academic_level_id` int(11) NOT NULL,
  `recommended_semester_id` int(11) NOT NULL,
  `course_type` varchar(50) NOT NULL COMMENT 'mandatory or elective',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `program_courses`
--

INSERT INTO `program_courses` (`program_course_id`, `academic_program_id`, `course_id`, `academic_level_id`, `recommended_semester_id`, `course_type`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, 1, 'mandatory', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 1, 2, 1, 2, 'mandatory', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(3, 1, 3, 2, 1, 'mandatory', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `registration_statuses`
--

CREATE TABLE `registration_statuses` (
  `registration_status_id` int(11) NOT NULL,
  `status_code` varchar(50) NOT NULL,
  `status_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `registration_statuses`
--

INSERT INTO `registration_statuses` (`registration_status_id`, `status_code`, `status_name`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'registered', 'Registered', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 'dropped', 'Dropped', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(3, 'withdrawn', 'Withdrawn', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(4, 'completed', 'Completed', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `result_statuses`
--

CREATE TABLE `result_statuses` (
  `result_status_id` int(11) NOT NULL,
  `status_code` varchar(50) NOT NULL,
  `status_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `result_statuses`
--

INSERT INTO `result_statuses` (`result_status_id`, `status_code`, `status_name`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'passed', 'Passed', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 'failed', 'Failed', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(3, 'deprived', 'Deprived', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(4, 'incomplete', 'Incomplete', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(5, 'pending_approval', 'Pending Approval', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `role_id` int(11) NOT NULL,
  `role_code` varchar(80) NOT NULL,
  `role_name` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_system_role` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`role_id`, `role_code`, `role_name`, `description`, `is_system_role`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'super_admin', 'Super Admin', NULL, 1, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 'university_president', 'University President', NULL, 1, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(3, 'vice_president', 'Vice President', NULL, 1, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(4, 'university_secretary_general', 'University Secretary General', NULL, 1, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(5, 'dean', 'Dean', NULL, 1, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(6, 'head_of_department', 'Head of Department', NULL, 1, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(7, 'doctor_instructor', 'Doctor / Instructor', NULL, 1, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(8, 'academic_advisor', 'Academic Advisor', NULL, 1, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(9, 'registration_officer', 'Registration Officer', NULL, 1, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(10, 'exam_officer', 'Exam Officer', NULL, 1, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(11, 'finance_officer', 'Finance Officer', NULL, 1, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(12, 'hr_officer', 'HR Officer', NULL, 1, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(13, 'librarian', 'Librarian', NULL, 1, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(14, 'board_member', 'Board Member', NULL, 1, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(15, 'student', 'Student', NULL, 1, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_permission_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `granted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role_permission_id`, `role_id`, `permission_id`, `granted_at`) VALUES
(1, 1, 1, '2026-05-24 12:41:57'),
(2, 1, 32, '2026-05-24 12:41:57'),
(3, 1, 2, '2026-05-24 12:41:57'),
(4, 1, 33, '2026-05-24 12:41:57'),
(5, 1, 3, '2026-05-24 12:41:57'),
(6, 1, 34, '2026-05-24 12:41:57'),
(7, 1, 4, '2026-05-24 12:41:57'),
(8, 1, 35, '2026-05-24 12:41:57'),
(9, 1, 5, '2026-05-24 12:41:57'),
(10, 1, 36, '2026-05-24 12:41:57'),
(11, 1, 6, '2026-05-24 12:41:57'),
(12, 1, 37, '2026-05-24 12:41:57'),
(13, 1, 7, '2026-05-24 12:41:57'),
(14, 1, 38, '2026-05-24 12:41:57'),
(15, 1, 8, '2026-05-24 12:41:57'),
(16, 1, 39, '2026-05-24 12:41:57'),
(17, 1, 9, '2026-05-24 12:41:57'),
(18, 1, 40, '2026-05-24 12:41:57'),
(19, 1, 10, '2026-05-24 12:41:57'),
(20, 1, 41, '2026-05-24 12:41:57'),
(21, 1, 11, '2026-05-24 12:41:57'),
(22, 1, 42, '2026-05-24 12:41:57'),
(23, 1, 12, '2026-05-24 12:41:57'),
(24, 1, 43, '2026-05-24 12:41:57'),
(25, 1, 13, '2026-05-24 12:41:57'),
(26, 1, 44, '2026-05-24 12:41:57'),
(27, 1, 14, '2026-05-24 12:41:57'),
(28, 1, 45, '2026-05-24 12:41:57'),
(29, 1, 15, '2026-05-24 12:41:57'),
(30, 1, 46, '2026-05-24 12:41:57'),
(31, 1, 16, '2026-05-24 12:41:57'),
(32, 1, 47, '2026-05-24 12:41:57'),
(64, 9, 8, '2026-05-24 12:41:57'),
(65, 9, 4, '2026-05-24 12:41:57'),
(66, 9, 7, '2026-05-24 12:41:57'),
(67, 9, 36, '2026-05-24 12:41:57'),
(68, 9, 1, '2026-05-24 12:41:57'),
(71, 10, 8, '2026-05-24 12:41:57'),
(72, 10, 37, '2026-05-24 12:41:57'),
(73, 10, 38, '2026-05-24 12:41:57'),
(74, 10, 1, '2026-05-24 12:41:57'),
(78, 7, 39, '2026-05-24 12:41:57'),
(79, 7, 4, '2026-05-24 12:41:57'),
(80, 7, 38, '2026-05-24 12:41:57'),
(81, 7, 7, '2026-05-24 12:41:57'),
(85, 15, 8, '2026-05-24 12:41:57'),
(86, 15, 4, '2026-05-24 12:41:57'),
(87, 15, 7, '2026-05-24 12:41:57'),
(88, 15, 5, '2026-05-24 12:41:57'),
(89, 15, 1, '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `semesters`
--

CREATE TABLE `semesters` (
  `semester_id` int(11) NOT NULL,
  `semester_code` varchar(50) NOT NULL,
  `semester_name` varchar(100) NOT NULL,
  `semester_order` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `semesters`
--

INSERT INTO `semesters` (`semester_id`, `semester_code`, `semester_name`, `semester_order`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'first', 'First Semester', 1, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 'second', 'Second Semester', 2, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(3, 'summer', 'Summer Semester', 3, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `student_id` int(11) NOT NULL,
  `student_number` varchar(50) NOT NULL,
  `admission_application_id` int(11) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `father_name` varchar(100) DEFAULT NULL,
  `mother_name` varchar(100) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `phone_number` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `nationality` varchar(100) DEFAULT NULL,
  `academic_program_id` int(11) NOT NULL,
  `current_academic_level_id` int(11) NOT NULL,
  `enrollment_date` date NOT NULL,
  `student_status_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`student_id`, `student_number`, `admission_application_id`, `first_name`, `last_name`, `father_name`, `mother_name`, `date_of_birth`, `gender`, `phone_number`, `email`, `address`, `nationality`, `academic_program_id`, `current_academic_level_id`, `enrollment_date`, `student_status_id`, `created_at`, `updated_at`) VALUES
(1, '2026-STAT-001', 1, 'Mutaz', 'Alabdullah', 'Mahmoud', 'Aisha', '2001-05-10', 'male', '+963900000010', 'mutaz.student@example.com', 'Aleppo', 'Syrian', 1, 1, '2025-09-01', 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `student_academic_terms`
--

CREATE TABLE `student_academic_terms` (
  `student_academic_term_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `academic_level_id` int(11) NOT NULL,
  `term_gpa` decimal(4,2) DEFAULT NULL,
  `cumulative_gpa` decimal(4,2) DEFAULT NULL,
  `total_registered_hours` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_attendance`
--

CREATE TABLE `student_attendance` (
  `student_attendance_id` int(11) NOT NULL,
  `attendance_session_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `attendance_status_id` int(11) NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `student_attendance`
--

INSERT INTO `student_attendance` (`student_attendance_id`, `attendance_session_id`, `student_id`, `attendance_status_id`, `notes`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, NULL, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 2, 1, 1, NULL, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `student_course_registrations`
--

CREATE TABLE `student_course_registrations` (
  `student_course_registration_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_offering_id` int(11) NOT NULL,
  `registration_date` date NOT NULL,
  `registered_by_user_id` int(11) NOT NULL,
  `advisor_user_id` int(11) DEFAULT NULL,
  `registration_status_id` int(11) NOT NULL,
  `result_status_id` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `student_course_registrations`
--

INSERT INTO `student_course_registrations` (`student_course_registration_id`, `student_id`, `course_offering_id`, `registration_date`, `registered_by_user_id`, `advisor_user_id`, `registration_status_id`, `result_status_id`, `notes`, `created_at`, `updated_at`) VALUES
(1, 1, 1, '2026-05-24', 2, 3, 1, NULL, NULL, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `student_course_results`
--

CREATE TABLE `student_course_results` (
  `student_course_result_id` int(11) NOT NULL,
  `student_course_registration_id` int(11) NOT NULL,
  `theoretical_total` decimal(5,2) NOT NULL DEFAULT 0.00,
  `practical_total` decimal(5,2) NOT NULL DEFAULT 0.00,
  `coursework_total` decimal(5,2) NOT NULL DEFAULT 0.00,
  `final_mark` decimal(5,2) NOT NULL DEFAULT 0.00,
  `result_status_id` int(11) NOT NULL,
  `is_deprived` tinyint(1) NOT NULL DEFAULT 0,
  `calculated_at` datetime DEFAULT NULL,
  `calculated_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `student_course_results`
--

INSERT INTO `student_course_results` (`student_course_result_id`, `student_course_registration_id`, `theoretical_total`, `practical_total`, `coursework_total`, `final_mark`, `result_status_id`, `is_deprived`, `calculated_at`, `calculated_by_user_id`, `created_at`, `updated_at`) VALUES
(1, 1, 46.00, 35.00, 0.00, 81.00, 1, 0, '2026-05-24 04:41:57', 2, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `student_credit_limits`
--

CREATE TABLE `student_credit_limits` (
  `credit_limit_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `min_credit_hours` int(11) NOT NULL DEFAULT 12,
  `max_credit_hours` int(11) NOT NULL DEFAULT 18,
  `is_excellent_student` tinyint(1) NOT NULL DEFAULT 0,
  `approved_by_user_id` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `student_credit_limits`
--

INSERT INTO `student_credit_limits` (`credit_limit_id`, `student_id`, `academic_year_id`, `semester_id`, `min_credit_hours`, `max_credit_hours`, `is_excellent_student`, `approved_by_user_id`, `notes`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, 12, 18, 0, 2, NULL, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `student_documents`
--

CREATE TABLE `student_documents` (
  `student_document_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `document_type_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_url` varchar(500) NOT NULL,
  `verification_status` varchar(50) NOT NULL DEFAULT 'pending',
  `verified_by_user_id` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `verification_notes` varchar(255) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `student_documents`
--

INSERT INTO `student_documents` (`student_document_id`, `student_id`, `document_type_id`, `file_name`, `file_url`, `verification_status`, `verified_by_user_id`, `verified_at`, `verification_notes`, `uploaded_at`) VALUES
(1, 1, 1, 'national_id_2026_STAT_001.pdf', '/uploads/students/2026-STAT-001/national_id.pdf', 'approved', 2, '2026-05-24 04:41:57', NULL, '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `student_grade_components`
--

CREATE TABLE `student_grade_components` (
  `student_grade_component_id` int(11) NOT NULL,
  `student_course_registration_id` int(11) NOT NULL,
  `grade_component_id` int(11) NOT NULL,
  `mark` decimal(5,2) DEFAULT NULL,
  `grade_status` varchar(50) NOT NULL DEFAULT 'draft',
  `entered_by_user_id` int(11) DEFAULT NULL,
  `entered_at` datetime DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `student_grade_components`
--

INSERT INTO `student_grade_components` (`student_grade_component_id`, `student_course_registration_id`, `grade_component_id`, `mark`, `grade_status`, `entered_by_user_id`, `entered_at`, `notes`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 16.00, 'submitted', 2, '2026-05-24 04:41:57', NULL, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 1, 2, 30.00, 'submitted', 2, '2026-05-24 04:41:57', NULL, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(3, 1, 3, 35.00, 'submitted', 2, '2026-05-24 04:41:57', NULL, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `student_statuses`
--

CREATE TABLE `student_statuses` (
  `student_status_id` int(11) NOT NULL,
  `status_code` varchar(50) NOT NULL,
  `status_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `student_statuses`
--

INSERT INTO `student_statuses` (`student_status_id`, `status_code`, `status_name`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'active', 'Active', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(2, 'frozen', 'Frozen', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(3, 'graduated', 'Graduated', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(4, 'withdrawn', 'Withdrawn', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(5, 'dismissed', 'Dismissed', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56'),
(6, 'suspended', 'Suspended', NULL, 1, '2026-05-24 12:41:56', '2026-05-24 12:41:56');

-- --------------------------------------------------------

--
-- Table structure for table `supplementary_exam_periods`
--

CREATE TABLE `supplementary_exam_periods` (
  `supplementary_exam_period_id` int(11) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `period_name` varchar(150) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `supplementary_exam_results`
--

CREATE TABLE `supplementary_exam_results` (
  `supplementary_exam_result_id` int(11) NOT NULL,
  `supplementary_exam_period_id` int(11) NOT NULL,
  `student_course_registration_id` int(11) NOT NULL,
  `theoretical_mark` decimal(5,2) NOT NULL,
  `entered_by_user_id` int(11) NOT NULL,
  `entered_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `system_modules`
--

CREATE TABLE `system_modules` (
  `module_id` int(11) NOT NULL,
  `module_code` varchar(80) NOT NULL,
  `module_name` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_modules`
--

INSERT INTO `system_modules` (`module_id`, `module_code`, `module_name`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'students', 'Students', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 'admissions', 'Admissions', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(3, 'academic_structure', 'Academic Structure', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(4, 'courses', 'Courses', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(5, 'registration', 'Registration', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(6, 'exams', 'Exams', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(7, 'grades', 'Grades', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(8, 'attendance', 'Attendance', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(9, 'hr', 'Human Resources', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(10, 'library', 'Library', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(11, 'reports', 'Reports', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(12, 'dashboards', 'Dashboards', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(13, 'boards', 'Boards', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(14, 'organizational_structure', 'Organizational Structure', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(15, 'system_settings', 'System Settings', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(16, 'users_permissions', 'Users and Permissions', NULL, 1, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(80) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `account_status_id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `board_member_id` int(11) DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `failed_login_attempts` int(11) NOT NULL DEFAULT 0,
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `account_status_id`, `student_id`, `employee_id`, `board_member_id`, `last_login_at`, `email_verified_at`, `failed_login_attempts`, `created_by_user_id`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'admin@rowad.edu', '$2y$example_hash', 1, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(2, 'registrar', 'registrar@rowad.edu', '$2y$example_hash', 1, NULL, 2, NULL, NULL, NULL, 0, NULL, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(3, 'doctor.ahmad', 'ahmad.khaled@rowad.edu', '$2y$example_hash', 1, NULL, 1, NULL, NULL, NULL, 0, NULL, '2026-05-24 12:41:57', '2026-05-24 12:41:57'),
(4, '2026-STAT-001', 'mutaz.student@example.com', '$2y$example_hash', 1, 1, NULL, NULL, NULL, NULL, 0, NULL, '2026-05-24 12:41:57', '2026-05-24 12:41:57');

-- --------------------------------------------------------

--
-- Table structure for table `user_activity_logs`
--

CREATE TABLE `user_activity_logs` (
  `activity_log_id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `module_code` varchar(80) DEFAULT NULL,
  `action_code` varchar(120) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `user_role_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `assigned_by_user_id` int(11) DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`user_role_id`, `user_id`, `role_id`, `assigned_by_user_id`, `assigned_at`, `is_active`) VALUES
(1, 1, 1, NULL, '2026-05-24 12:41:57', 1),
(2, 2, 9, NULL, '2026-05-24 12:41:57', 1),
(3, 3, 7, NULL, '2026-05-24 12:41:57', 1),
(4, 4, 15, NULL, '2026-05-24 12:41:57', 1);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_attendance_percentage`
-- (See below for the actual view)
--
CREATE TABLE `vw_attendance_percentage` (
`student_id` int(11)
,`student_number` varchar(50)
,`student_full_name` varchar(201)
,`course_offering_id` int(11)
,`course_code` varchar(50)
,`course_name` varchar(200)
,`total_sessions_recorded` bigint(21)
,`absent_sessions` decimal(22,0)
,`absence_percentage` decimal(28,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_course_offering_summary`
-- (See below for the actual view)
--
CREATE TABLE `vw_course_offering_summary` (
`course_offering_id` int(11)
,`year_name` varchar(50)
,`semester_name` varchar(100)
,`course_code` varchar(50)
,`course_name` varchar(200)
,`capacity` int(11)
,`available_seats` int(11)
,`status` varchar(50)
,`registered_students` bigint(21)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_deprived_students`
-- (See below for the actual view)
--
CREATE TABLE `vw_deprived_students` (
`student_id` int(11)
,`student_number` varchar(50)
,`student_full_name` varchar(201)
,`course_offering_id` int(11)
,`course_code` varchar(50)
,`course_name` varchar(200)
,`total_sessions_recorded` bigint(21)
,`absent_sessions` decimal(22,0)
,`absence_percentage` decimal(28,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_final_grade_summary`
-- (See below for the actual view)
--
CREATE TABLE `vw_final_grade_summary` (
`course_offering_id` int(11)
,`course_code` varchar(50)
,`course_name` varchar(200)
,`student_number` varchar(50)
,`student_full_name` varchar(201)
,`theoretical_total` decimal(5,2)
,`practical_total` decimal(5,2)
,`coursework_total` decimal(5,2)
,`final_mark` decimal(5,2)
,`result_status` varchar(100)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_grade_appeals_report`
-- (See below for the actual view)
--
CREATE TABLE `vw_grade_appeals_report` (
`grade_appeal_id` int(11)
,`student_number` varchar(50)
,`student_full_name` varchar(201)
,`course_code` varchar(50)
,`course_name` varchar(200)
,`appeal_status` varchar(100)
,`submitted_at` datetime
,`decision_date` datetime
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_library_borrowings`
-- (See below for the actual view)
--
CREATE TABLE `vw_library_borrowings` (
`library_borrowing_id` int(11)
,`book_title` varchar(250)
,`copy_barcode` varchar(80)
,`borrower_number` varchar(50)
,`borrower_name` varchar(201)
,`borrowed_at` datetime
,`due_at` datetime
,`returned_at` datetime
,`borrowing_status` varchar(50)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_organizational_hierarchy`
-- (See below for the actual view)
--
CREATE TABLE `vw_organizational_hierarchy` (
`organizational_unit_id` int(11)
,`unit_name` varchar(200)
,`unit_type` varchar(100)
,`parent_unit_name` varchar(200)
,`is_active` tinyint(1)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_pending_grade_approvals`
-- (See below for the actual view)
--
CREATE TABLE `vw_pending_grade_approvals` (
`grade_approval_id` int(11)
,`course_offering_id` int(11)
,`course_code` varchar(50)
,`course_name` varchar(200)
,`approval_status` varchar(100)
,`submitted_at` datetime
,`approval_notes` text
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_student_academic_info`
-- (See below for the actual view)
--
CREATE TABLE `vw_student_academic_info` (
`student_id` int(11)
,`student_number` varchar(50)
,`student_full_name` varchar(201)
,`college_name` varchar(200)
,`department_name` varchar(200)
,`program_name` varchar(200)
,`current_academic_level` varchar(100)
,`student_status` varchar(100)
,`enrollment_date` date
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_student_basic_info`
-- (See below for the actual view)
--
CREATE TABLE `vw_student_basic_info` (
`student_id` int(11)
,`student_number` varchar(50)
,`student_full_name` varchar(201)
,`father_name` varchar(100)
,`mother_name` varchar(100)
,`date_of_birth` date
,`gender` varchar(20)
,`phone_number` varchar(30)
,`email` varchar(150)
,`nationality` varchar(100)
,`student_status` varchar(100)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_student_registered_courses`
-- (See below for the actual view)
--
CREATE TABLE `vw_student_registered_courses` (
`student_course_registration_id` int(11)
,`student_number` varchar(50)
,`student_full_name` varchar(201)
,`academic_year` varchar(50)
,`semester_name` varchar(100)
,`course_offering_id` int(11)
,`course_code` varchar(50)
,`course_name` varchar(200)
,`credit_hours` int(11)
,`registration_status` varchar(100)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_student_transcript`
-- (See below for the actual view)
--
CREATE TABLE `vw_student_transcript` (
`student_number` varchar(50)
,`student_full_name` varchar(201)
,`year_name` varchar(50)
,`semester_name` varchar(100)
,`course_code` varchar(50)
,`course_name` varchar(200)
,`credit_hours` int(11)
,`theoretical_total` decimal(5,2)
,`practical_total` decimal(5,2)
,`coursework_total` decimal(5,2)
,`final_mark` decimal(5,2)
,`result_status` varchar(100)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_users_roles_permissions`
-- (See below for the actual view)
--
CREATE TABLE `vw_users_roles_permissions` (
`user_id` int(11)
,`username` varchar(80)
,`email` varchar(150)
,`role_name` varchar(150)
,`module_name` varchar(150)
,`permission_code` varchar(120)
,`permission_name` varchar(150)
);

-- --------------------------------------------------------

--
-- Structure for view `vw_attendance_percentage`
--
DROP TABLE IF EXISTS `vw_attendance_percentage`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_attendance_percentage`  AS SELECT `s`.`student_id` AS `student_id`, `s`.`student_number` AS `student_number`, concat(`s`.`first_name`,' ',`s`.`last_name`) AS `student_full_name`, `co`.`course_offering_id` AS `course_offering_id`, `crs`.`course_code` AS `course_code`, `crs`.`course_name` AS `course_name`, count(`sa`.`student_attendance_id`) AS `total_sessions_recorded`, sum(case when `ats`.`counts_as_absent` = 1 then 1 else 0 end) AS `absent_sessions`, round(sum(case when `ats`.`counts_as_absent` = 1 then 1 else 0 end) / nullif(count(`sa`.`student_attendance_id`),0) * 100,2) AS `absence_percentage` FROM (((((`student_attendance` `sa` join `attendance_sessions` `ase` on(`ase`.`attendance_session_id` = `sa`.`attendance_session_id`)) join `course_offerings` `co` on(`co`.`course_offering_id` = `ase`.`course_offering_id`)) join `courses` `crs` on(`crs`.`course_id` = `co`.`course_id`)) join `students` `s` on(`s`.`student_id` = `sa`.`student_id`)) join `attendance_statuses` `ats` on(`ats`.`attendance_status_id` = `sa`.`attendance_status_id`)) GROUP BY `s`.`student_id`, `s`.`student_number`, concat(`s`.`first_name`,' ',`s`.`last_name`), `co`.`course_offering_id`, `crs`.`course_code`, `crs`.`course_name` ;

-- --------------------------------------------------------

--
-- Structure for view `vw_course_offering_summary`
--
DROP TABLE IF EXISTS `vw_course_offering_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_course_offering_summary`  AS SELECT `co`.`course_offering_id` AS `course_offering_id`, `ay`.`year_name` AS `year_name`, `sem`.`semester_name` AS `semester_name`, `crs`.`course_code` AS `course_code`, `crs`.`course_name` AS `course_name`, `co`.`capacity` AS `capacity`, `co`.`available_seats` AS `available_seats`, `co`.`status` AS `status`, count(`scr`.`student_course_registration_id`) AS `registered_students` FROM ((((`course_offerings` `co` join `courses` `crs` on(`crs`.`course_id` = `co`.`course_id`)) join `academic_years` `ay` on(`ay`.`academic_year_id` = `co`.`academic_year_id`)) join `semesters` `sem` on(`sem`.`semester_id` = `co`.`semester_id`)) left join `student_course_registrations` `scr` on(`scr`.`course_offering_id` = `co`.`course_offering_id`)) GROUP BY `co`.`course_offering_id`, `ay`.`year_name`, `sem`.`semester_name`, `crs`.`course_code`, `crs`.`course_name`, `co`.`capacity`, `co`.`available_seats`, `co`.`status` ;

-- --------------------------------------------------------

--
-- Structure for view `vw_deprived_students`
--
DROP TABLE IF EXISTS `vw_deprived_students`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_deprived_students`  AS SELECT `vw_attendance_percentage`.`student_id` AS `student_id`, `vw_attendance_percentage`.`student_number` AS `student_number`, `vw_attendance_percentage`.`student_full_name` AS `student_full_name`, `vw_attendance_percentage`.`course_offering_id` AS `course_offering_id`, `vw_attendance_percentage`.`course_code` AS `course_code`, `vw_attendance_percentage`.`course_name` AS `course_name`, `vw_attendance_percentage`.`total_sessions_recorded` AS `total_sessions_recorded`, `vw_attendance_percentage`.`absent_sessions` AS `absent_sessions`, `vw_attendance_percentage`.`absence_percentage` AS `absence_percentage` FROM `vw_attendance_percentage` WHERE `vw_attendance_percentage`.`absence_percentage` > 15 ;

-- --------------------------------------------------------

--
-- Structure for view `vw_final_grade_summary`
--
DROP TABLE IF EXISTS `vw_final_grade_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_final_grade_summary`  AS SELECT `co`.`course_offering_id` AS `course_offering_id`, `crs`.`course_code` AS `course_code`, `crs`.`course_name` AS `course_name`, `s`.`student_number` AS `student_number`, concat(`s`.`first_name`,' ',`s`.`last_name`) AS `student_full_name`, `res`.`theoretical_total` AS `theoretical_total`, `res`.`practical_total` AS `practical_total`, `res`.`coursework_total` AS `coursework_total`, `res`.`final_mark` AS `final_mark`, `rst`.`status_name` AS `result_status` FROM (((((`student_course_results` `res` join `student_course_registrations` `scr` on(`scr`.`student_course_registration_id` = `res`.`student_course_registration_id`)) join `students` `s` on(`s`.`student_id` = `scr`.`student_id`)) join `course_offerings` `co` on(`co`.`course_offering_id` = `scr`.`course_offering_id`)) join `courses` `crs` on(`crs`.`course_id` = `co`.`course_id`)) join `result_statuses` `rst` on(`rst`.`result_status_id` = `res`.`result_status_id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `vw_grade_appeals_report`
--
DROP TABLE IF EXISTS `vw_grade_appeals_report`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_grade_appeals_report`  AS SELECT `ga`.`grade_appeal_id` AS `grade_appeal_id`, `s`.`student_number` AS `student_number`, concat(`s`.`first_name`,' ',`s`.`last_name`) AS `student_full_name`, `crs`.`course_code` AS `course_code`, `crs`.`course_name` AS `course_name`, `aps`.`status_name` AS `appeal_status`, `ga`.`submitted_at` AS `submitted_at`, `ga`.`decision_date` AS `decision_date` FROM (((((`grade_appeals` `ga` join `students` `s` on(`s`.`student_id` = `ga`.`student_id`)) join `appeal_statuses` `aps` on(`aps`.`appeal_status_id` = `ga`.`appeal_status_id`)) join `student_course_registrations` `scr` on(`scr`.`student_course_registration_id` = `ga`.`student_course_registration_id`)) join `course_offerings` `co` on(`co`.`course_offering_id` = `scr`.`course_offering_id`)) join `courses` `crs` on(`crs`.`course_id` = `co`.`course_id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `vw_library_borrowings`
--
DROP TABLE IF EXISTS `vw_library_borrowings`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_library_borrowings`  AS SELECT `lb`.`library_borrowing_id` AS `library_borrowing_id`, `b`.`title` AS `book_title`, `c`.`copy_barcode` AS `copy_barcode`, coalesce(`s`.`student_number`,`e`.`employee_number`) AS `borrower_number`, coalesce(concat(`s`.`first_name`,' ',`s`.`last_name`),concat(`e`.`first_name`,' ',`e`.`last_name`)) AS `borrower_name`, `lb`.`borrowed_at` AS `borrowed_at`, `lb`.`due_at` AS `due_at`, `lb`.`returned_at` AS `returned_at`, `lb`.`borrowing_status` AS `borrowing_status` FROM ((((`library_borrowings` `lb` join `library_book_copies` `c` on(`c`.`library_book_copy_id` = `lb`.`library_book_copy_id`)) join `library_books` `b` on(`b`.`library_book_id` = `c`.`library_book_id`)) left join `students` `s` on(`s`.`student_id` = `lb`.`student_id`)) left join `employees` `e` on(`e`.`employee_id` = `lb`.`employee_id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `vw_organizational_hierarchy`
--
DROP TABLE IF EXISTS `vw_organizational_hierarchy`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_organizational_hierarchy`  AS SELECT `child`.`organizational_unit_id` AS `organizational_unit_id`, `child`.`unit_name` AS `unit_name`, `outype`.`type_name` AS `unit_type`, `parent`.`unit_name` AS `parent_unit_name`, `child`.`is_active` AS `is_active` FROM ((`organizational_units` `child` join `organizational_unit_types` `outype` on(`outype`.`unit_type_id` = `child`.`unit_type_id`)) left join `organizational_units` `parent` on(`parent`.`organizational_unit_id` = `child`.`parent_unit_id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `vw_pending_grade_approvals`
--
DROP TABLE IF EXISTS `vw_pending_grade_approvals`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_pending_grade_approvals`  AS SELECT `ga`.`grade_approval_id` AS `grade_approval_id`, `co`.`course_offering_id` AS `course_offering_id`, `crs`.`course_code` AS `course_code`, `crs`.`course_name` AS `course_name`, `aps`.`status_name` AS `approval_status`, `ga`.`submitted_at` AS `submitted_at`, `ga`.`approval_notes` AS `approval_notes` FROM (((`grade_approvals` `ga` join `approval_statuses` `aps` on(`aps`.`approval_status_id` = `ga`.`approval_status_id`)) join `course_offerings` `co` on(`co`.`course_offering_id` = `ga`.`course_offering_id`)) join `courses` `crs` on(`crs`.`course_id` = `co`.`course_id`)) WHERE `aps`.`status_code` = 'pending' ;

-- --------------------------------------------------------

--
-- Structure for view `vw_student_academic_info`
--
DROP TABLE IF EXISTS `vw_student_academic_info`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_student_academic_info`  AS SELECT `s`.`student_id` AS `student_id`, `s`.`student_number` AS `student_number`, concat(`s`.`first_name`,' ',`s`.`last_name`) AS `student_full_name`, `c`.`college_name` AS `college_name`, `d`.`department_name` AS `department_name`, `ap`.`program_name` AS `program_name`, `al`.`level_name` AS `current_academic_level`, `ss`.`status_name` AS `student_status`, `s`.`enrollment_date` AS `enrollment_date` FROM (((((`students` `s` join `academic_programs` `ap` on(`ap`.`academic_program_id` = `s`.`academic_program_id`)) join `departments` `d` on(`d`.`department_id` = `ap`.`department_id`)) join `colleges` `c` on(`c`.`college_id` = `d`.`college_id`)) join `academic_levels` `al` on(`al`.`academic_level_id` = `s`.`current_academic_level_id`)) join `student_statuses` `ss` on(`ss`.`student_status_id` = `s`.`student_status_id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `vw_student_basic_info`
--
DROP TABLE IF EXISTS `vw_student_basic_info`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_student_basic_info`  AS SELECT `s`.`student_id` AS `student_id`, `s`.`student_number` AS `student_number`, concat(`s`.`first_name`,' ',`s`.`last_name`) AS `student_full_name`, `s`.`father_name` AS `father_name`, `s`.`mother_name` AS `mother_name`, `s`.`date_of_birth` AS `date_of_birth`, `s`.`gender` AS `gender`, `s`.`phone_number` AS `phone_number`, `s`.`email` AS `email`, `s`.`nationality` AS `nationality`, `ss`.`status_name` AS `student_status` FROM (`students` `s` join `student_statuses` `ss` on(`ss`.`student_status_id` = `s`.`student_status_id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `vw_student_registered_courses`
--
DROP TABLE IF EXISTS `vw_student_registered_courses`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_student_registered_courses`  AS SELECT `scr`.`student_course_registration_id` AS `student_course_registration_id`, `s`.`student_number` AS `student_number`, concat(`s`.`first_name`,' ',`s`.`last_name`) AS `student_full_name`, `ay`.`year_name` AS `academic_year`, `sem`.`semester_name` AS `semester_name`, `co`.`course_offering_id` AS `course_offering_id`, `crs`.`course_code` AS `course_code`, `crs`.`course_name` AS `course_name`, `crs`.`credit_hours` AS `credit_hours`, `rs`.`status_name` AS `registration_status` FROM ((((((`student_course_registrations` `scr` join `students` `s` on(`s`.`student_id` = `scr`.`student_id`)) join `course_offerings` `co` on(`co`.`course_offering_id` = `scr`.`course_offering_id`)) join `courses` `crs` on(`crs`.`course_id` = `co`.`course_id`)) join `academic_years` `ay` on(`ay`.`academic_year_id` = `co`.`academic_year_id`)) join `semesters` `sem` on(`sem`.`semester_id` = `co`.`semester_id`)) join `registration_statuses` `rs` on(`rs`.`registration_status_id` = `scr`.`registration_status_id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `vw_student_transcript`
--
DROP TABLE IF EXISTS `vw_student_transcript`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_student_transcript`  AS SELECT `s`.`student_number` AS `student_number`, concat(`s`.`first_name`,' ',`s`.`last_name`) AS `student_full_name`, `ay`.`year_name` AS `year_name`, `sem`.`semester_name` AS `semester_name`, `crs`.`course_code` AS `course_code`, `crs`.`course_name` AS `course_name`, `crs`.`credit_hours` AS `credit_hours`, `res`.`theoretical_total` AS `theoretical_total`, `res`.`practical_total` AS `practical_total`, `res`.`coursework_total` AS `coursework_total`, `res`.`final_mark` AS `final_mark`, `rst`.`status_name` AS `result_status` FROM (((((((`student_course_results` `res` join `student_course_registrations` `scr` on(`scr`.`student_course_registration_id` = `res`.`student_course_registration_id`)) join `students` `s` on(`s`.`student_id` = `scr`.`student_id`)) join `course_offerings` `co` on(`co`.`course_offering_id` = `scr`.`course_offering_id`)) join `courses` `crs` on(`crs`.`course_id` = `co`.`course_id`)) join `academic_years` `ay` on(`ay`.`academic_year_id` = `co`.`academic_year_id`)) join `semesters` `sem` on(`sem`.`semester_id` = `co`.`semester_id`)) join `result_statuses` `rst` on(`rst`.`result_status_id` = `res`.`result_status_id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `vw_users_roles_permissions`
--
DROP TABLE IF EXISTS `vw_users_roles_permissions`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_users_roles_permissions`  AS SELECT `u`.`user_id` AS `user_id`, `u`.`username` AS `username`, `u`.`email` AS `email`, `r`.`role_name` AS `role_name`, `sm`.`module_name` AS `module_name`, `p`.`permission_code` AS `permission_code`, `p`.`permission_name` AS `permission_name` FROM (((((`users` `u` join `user_roles` `ur` on(`ur`.`user_id` = `u`.`user_id` and `ur`.`is_active` = 1)) join `roles` `r` on(`r`.`role_id` = `ur`.`role_id`)) join `role_permissions` `rp` on(`rp`.`role_id` = `r`.`role_id`)) join `permissions` `p` on(`p`.`permission_id` = `rp`.`permission_id`)) join `system_modules` `sm` on(`sm`.`module_id` = `p`.`module_id`)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `academic_levels`
--
ALTER TABLE `academic_levels`
  ADD PRIMARY KEY (`academic_level_id`),
  ADD UNIQUE KEY `level_code` (`level_code`);

--
-- Indexes for table `academic_programs`
--
ALTER TABLE `academic_programs`
  ADD PRIMARY KEY (`academic_program_id`),
  ADD UNIQUE KEY `program_code` (`program_code`),
  ADD KEY `idx_programs_department` (`department_id`);

--
-- Indexes for table `academic_years`
--
ALTER TABLE `academic_years`
  ADD PRIMARY KEY (`academic_year_id`),
  ADD UNIQUE KEY `year_name` (`year_name`);

--
-- Indexes for table `account_statuses`
--
ALTER TABLE `account_statuses`
  ADD PRIMARY KEY (`account_status_id`),
  ADD UNIQUE KEY `status_code` (`status_code`);

--
-- Indexes for table `admission_applications`
--
ALTER TABLE `admission_applications`
  ADD PRIMARY KEY (`admission_application_id`),
  ADD KEY `fk_admission_applicant` (`applicant_id`),
  ADD KEY `fk_admission_program` (`academic_program_id`),
  ADD KEY `fk_admission_year` (`academic_year_id`),
  ADD KEY `fk_admission_decided_by` (`decided_by_user_id`);

--
-- Indexes for table `appeal_statuses`
--
ALTER TABLE `appeal_statuses`
  ADD PRIMARY KEY (`appeal_status_id`),
  ADD UNIQUE KEY `status_code` (`status_code`);

--
-- Indexes for table `applicants`
--
ALTER TABLE `applicants`
  ADD PRIMARY KEY (`applicant_id`),
  ADD UNIQUE KEY `applicant_number` (`applicant_number`);

--
-- Indexes for table `approval_statuses`
--
ALTER TABLE `approval_statuses`
  ADD PRIMARY KEY (`approval_status_id`),
  ADD UNIQUE KEY `status_code` (`status_code`);

--
-- Indexes for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  ADD PRIMARY KEY (`attendance_session_id`),
  ADD KEY `fk_att_session_faculty` (`faculty_member_id`),
  ADD KEY `fk_att_session_created_by` (`created_by_user_id`),
  ADD KEY `idx_att_session_offering` (`course_offering_id`),
  ADD KEY `idx_att_session_date` (`session_date`);

--
-- Indexes for table `attendance_statuses`
--
ALTER TABLE `attendance_statuses`
  ADD PRIMARY KEY (`attendance_status_id`),
  ADD UNIQUE KEY `status_code` (`status_code`);

--
-- Indexes for table `boards`
--
ALTER TABLE `boards`
  ADD PRIMARY KEY (`board_id`),
  ADD UNIQUE KEY `board_code` (`board_code`),
  ADD KEY `fk_board_org_unit` (`organizational_unit_id`);

--
-- Indexes for table `board_decisions`
--
ALTER TABLE `board_decisions`
  ADD PRIMARY KEY (`board_decision_id`),
  ADD KEY `fk_decision_meeting` (`board_meeting_id`);

--
-- Indexes for table `board_decision_attachments`
--
ALTER TABLE `board_decision_attachments`
  ADD PRIMARY KEY (`attachment_id`),
  ADD KEY `fk_decision_attachment_decision` (`board_decision_id`),
  ADD KEY `fk_decision_attachment_user` (`uploaded_by_user_id`);

--
-- Indexes for table `board_meetings`
--
ALTER TABLE `board_meetings`
  ADD PRIMARY KEY (`board_meeting_id`),
  ADD KEY `fk_meeting_board` (`board_id`),
  ADD KEY `fk_meeting_created_by` (`created_by_user_id`);

--
-- Indexes for table `board_members`
--
ALTER TABLE `board_members`
  ADD PRIMARY KEY (`board_member_id`),
  ADD KEY `fk_board_member_board` (`board_id`),
  ADD KEY `fk_board_member_employee` (`employee_id`);

--
-- Indexes for table `colleges`
--
ALTER TABLE `colleges`
  ADD PRIMARY KEY (`college_id`),
  ADD UNIQUE KEY `college_code` (`college_code`),
  ADD UNIQUE KEY `organizational_unit_id` (`organizational_unit_id`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`course_id`),
  ADD UNIQUE KEY `course_code` (`course_code`);

--
-- Indexes for table `course_departments`
--
ALTER TABLE `course_departments`
  ADD PRIMARY KEY (`course_department_id`),
  ADD UNIQUE KEY `uq_course_department` (`course_id`,`department_id`),
  ADD KEY `fk_course_dept_dept` (`department_id`);

--
-- Indexes for table `course_instructors`
--
ALTER TABLE `course_instructors`
  ADD PRIMARY KEY (`course_instructor_id`),
  ADD UNIQUE KEY `uq_course_instructor` (`course_id`,`faculty_member_id`),
  ADD KEY `fk_course_inst_faculty` (`faculty_member_id`);

--
-- Indexes for table `course_offerings`
--
ALTER TABLE `course_offerings`
  ADD PRIMARY KEY (`course_offering_id`),
  ADD KEY `fk_offering_semester` (`semester_id`),
  ADD KEY `fk_offering_dept` (`department_id`),
  ADD KEY `fk_offering_program` (`academic_program_id`),
  ADD KEY `fk_offering_faculty` (`faculty_member_id`),
  ADD KEY `idx_offering_year_semester` (`academic_year_id`,`semester_id`),
  ADD KEY `idx_offering_course` (`course_id`),
  ADD KEY `idx_offering_status` (`status`);

--
-- Indexes for table `course_prerequisites`
--
ALTER TABLE `course_prerequisites`
  ADD PRIMARY KEY (`course_prerequisite_id`),
  ADD UNIQUE KEY `uq_course_prerequisite` (`course_id`,`prerequisite_course_id`),
  ADD KEY `fk_prereq_required_course` (`prerequisite_course_id`),
  ADD KEY `fk_prereq_min_status` (`minimum_result_status_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`),
  ADD UNIQUE KEY `department_code` (`department_code`),
  ADD UNIQUE KEY `organizational_unit_id` (`organizational_unit_id`),
  ADD KEY `idx_departments_college` (`college_id`);

--
-- Indexes for table `document_types`
--
ALTER TABLE `document_types`
  ADD PRIMARY KEY (`document_type_id`),
  ADD UNIQUE KEY `type_code` (`type_code`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`employee_id`),
  ADD UNIQUE KEY `employee_number` (`employee_number`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_employees_unit` (`organizational_unit_id`),
  ADD KEY `idx_employees_type` (`employee_type_id`),
  ADD KEY `idx_employees_status` (`employee_status_id`);

--
-- Indexes for table `employee_positions`
--
ALTER TABLE `employee_positions`
  ADD PRIMARY KEY (`employee_position_id`),
  ADD KEY `fk_emp_pos_position` (`position_id`),
  ADD KEY `idx_employee_positions_employee` (`employee_id`),
  ADD KEY `idx_employee_positions_unit` (`organizational_unit_id`);

--
-- Indexes for table `employee_statuses`
--
ALTER TABLE `employee_statuses`
  ADD PRIMARY KEY (`employee_status_id`),
  ADD UNIQUE KEY `status_code` (`status_code`);

--
-- Indexes for table `employee_types`
--
ALTER TABLE `employee_types`
  ADD PRIMARY KEY (`employee_type_id`),
  ADD UNIQUE KEY `type_code` (`type_code`);

--
-- Indexes for table `employee_unit_assignments`
--
ALTER TABLE `employee_unit_assignments`
  ADD PRIMARY KEY (`assignment_id`),
  ADD KEY `fk_emp_unit_employee` (`employee_id`),
  ADD KEY `fk_emp_unit_unit` (`organizational_unit_id`);

--
-- Indexes for table `faculty_members`
--
ALTER TABLE `faculty_members`
  ADD PRIMARY KEY (`faculty_member_id`),
  ADD UNIQUE KEY `employee_id` (`employee_id`);

--
-- Indexes for table `grade_appeals`
--
ALTER TABLE `grade_appeals`
  ADD PRIMARY KEY (`grade_appeal_id`),
  ADD KEY `fk_appeal_student` (`student_id`),
  ADD KEY `fk_appeal_reg` (`student_course_registration_id`),
  ADD KEY `fk_appeal_reviewed_by` (`reviewed_by_user_id`),
  ADD KEY `idx_grade_appeal_status` (`appeal_status_id`);

--
-- Indexes for table `grade_approvals`
--
ALTER TABLE `grade_approvals`
  ADD PRIMARY KEY (`grade_approval_id`),
  ADD KEY `fk_approval_offering` (`course_offering_id`),
  ADD KEY `fk_approval_submitted_by` (`submitted_by_user_id`),
  ADD KEY `fk_approval_approved_by` (`approved_by_user_id`),
  ADD KEY `idx_grade_approval_status` (`approval_status_id`);

--
-- Indexes for table `grade_audit_logs`
--
ALTER TABLE `grade_audit_logs`
  ADD PRIMARY KEY (`grade_audit_log_id`),
  ADD KEY `fk_grade_audit_grade` (`student_grade_component_id`),
  ADD KEY `fk_grade_audit_user` (`changed_by_user_id`);

--
-- Indexes for table `grade_components`
--
ALTER TABLE `grade_components`
  ADD PRIMARY KEY (`grade_component_id`),
  ADD KEY `idx_grade_comp_offering` (`course_offering_id`);

--
-- Indexes for table `grading_policies`
--
ALTER TABLE `grading_policies`
  ADD PRIMARY KEY (`grading_policy_id`);

--
-- Indexes for table `library_authors`
--
ALTER TABLE `library_authors`
  ADD PRIMARY KEY (`library_author_id`);

--
-- Indexes for table `library_books`
--
ALTER TABLE `library_books`
  ADD PRIMARY KEY (`library_book_id`),
  ADD UNIQUE KEY `isbn` (`isbn`),
  ADD KEY `fk_book_category` (`category_id`);

--
-- Indexes for table `library_book_authors`
--
ALTER TABLE `library_book_authors`
  ADD PRIMARY KEY (`book_author_id`),
  ADD UNIQUE KEY `uq_book_author` (`library_book_id`,`library_author_id`),
  ADD KEY `fk_book_author_author` (`library_author_id`);

--
-- Indexes for table `library_book_copies`
--
ALTER TABLE `library_book_copies`
  ADD PRIMARY KEY (`library_book_copy_id`),
  ADD UNIQUE KEY `copy_barcode` (`copy_barcode`),
  ADD KEY `fk_copy_book` (`library_book_id`);

--
-- Indexes for table `library_borrowings`
--
ALTER TABLE `library_borrowings`
  ADD PRIMARY KEY (`library_borrowing_id`),
  ADD KEY `fk_borrow_copy` (`library_book_copy_id`),
  ADD KEY `fk_borrow_student` (`student_id`),
  ADD KEY `fk_borrow_employee` (`employee_id`),
  ADD KEY `fk_borrow_created_by` (`created_by_user_id`);

--
-- Indexes for table `library_categories`
--
ALTER TABLE `library_categories`
  ADD PRIMARY KEY (`library_category_id`),
  ADD UNIQUE KEY `category_name` (`category_name`);

--
-- Indexes for table `login_audit_logs`
--
ALTER TABLE `login_audit_logs`
  ADD PRIMARY KEY (`login_audit_id`),
  ADD KEY `fk_login_user` (`user_id`);

--
-- Indexes for table `meeting_attendees`
--
ALTER TABLE `meeting_attendees`
  ADD PRIMARY KEY (`meeting_attendee_id`),
  ADD UNIQUE KEY `uq_meeting_attendee` (`board_meeting_id`,`board_member_id`),
  ADD KEY `fk_attendee_member` (`board_member_id`);

--
-- Indexes for table `organizational_units`
--
ALTER TABLE `organizational_units`
  ADD PRIMARY KEY (`organizational_unit_id`),
  ADD UNIQUE KEY `unit_code` (`unit_code`),
  ADD KEY `idx_org_units_parent` (`parent_unit_id`),
  ADD KEY `idx_org_units_type` (`unit_type_id`);

--
-- Indexes for table `organizational_unit_types`
--
ALTER TABLE `organizational_unit_types`
  ADD PRIMARY KEY (`unit_type_id`),
  ADD UNIQUE KEY `type_code` (`type_code`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`token_id`),
  ADD KEY `fk_reset_user` (`user_id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`permission_id`),
  ADD UNIQUE KEY `permission_code` (`permission_code`),
  ADD KEY `fk_permission_module` (`module_id`);

--
-- Indexes for table `positions`
--
ALTER TABLE `positions`
  ADD PRIMARY KEY (`position_id`),
  ADD UNIQUE KEY `position_code` (`position_code`);

--
-- Indexes for table `program_courses`
--
ALTER TABLE `program_courses`
  ADD PRIMARY KEY (`program_course_id`),
  ADD UNIQUE KEY `uq_program_course` (`academic_program_id`,`course_id`),
  ADD KEY `fk_prog_course_course` (`course_id`),
  ADD KEY `fk_prog_course_level` (`academic_level_id`),
  ADD KEY `fk_prog_course_semester` (`recommended_semester_id`);

--
-- Indexes for table `registration_statuses`
--
ALTER TABLE `registration_statuses`
  ADD PRIMARY KEY (`registration_status_id`),
  ADD UNIQUE KEY `status_code` (`status_code`);

--
-- Indexes for table `result_statuses`
--
ALTER TABLE `result_statuses`
  ADD PRIMARY KEY (`result_status_id`),
  ADD UNIQUE KEY `status_code` (`status_code`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`role_id`),
  ADD UNIQUE KEY `role_code` (`role_code`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_permission_id`),
  ADD UNIQUE KEY `uq_role_permission` (`role_id`,`permission_id`),
  ADD KEY `fk_role_perm_permission` (`permission_id`);

--
-- Indexes for table `semesters`
--
ALTER TABLE `semesters`
  ADD PRIMARY KEY (`semester_id`),
  ADD UNIQUE KEY `semester_code` (`semester_code`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`student_id`),
  ADD UNIQUE KEY `student_number` (`student_number`),
  ADD UNIQUE KEY `admission_application_id` (`admission_application_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_student_level` (`current_academic_level_id`),
  ADD KEY `idx_students_program` (`academic_program_id`),
  ADD KEY `idx_students_status` (`student_status_id`),
  ADD KEY `idx_students_number` (`student_number`);

--
-- Indexes for table `student_academic_terms`
--
ALTER TABLE `student_academic_terms`
  ADD PRIMARY KEY (`student_academic_term_id`),
  ADD UNIQUE KEY `uq_student_term` (`student_id`,`academic_year_id`,`semester_id`),
  ADD KEY `fk_sat_year` (`academic_year_id`),
  ADD KEY `fk_sat_semester` (`semester_id`),
  ADD KEY `fk_sat_level` (`academic_level_id`);

--
-- Indexes for table `student_attendance`
--
ALTER TABLE `student_attendance`
  ADD PRIMARY KEY (`student_attendance_id`),
  ADD UNIQUE KEY `uq_student_attendance` (`attendance_session_id`,`student_id`),
  ADD KEY `fk_stu_att_status` (`attendance_status_id`),
  ADD KEY `idx_student_attendance_student` (`student_id`);

--
-- Indexes for table `student_course_registrations`
--
ALTER TABLE `student_course_registrations`
  ADD PRIMARY KEY (`student_course_registration_id`),
  ADD UNIQUE KEY `uq_student_course_offering` (`student_id`,`course_offering_id`),
  ADD KEY `fk_reg_registered_by` (`registered_by_user_id`),
  ADD KEY `fk_reg_advisor` (`advisor_user_id`),
  ADD KEY `fk_reg_result_status` (`result_status_id`),
  ADD KEY `idx_reg_student` (`student_id`),
  ADD KEY `idx_reg_offering` (`course_offering_id`),
  ADD KEY `idx_reg_status` (`registration_status_id`);

--
-- Indexes for table `student_course_results`
--
ALTER TABLE `student_course_results`
  ADD PRIMARY KEY (`student_course_result_id`),
  ADD UNIQUE KEY `student_course_registration_id` (`student_course_registration_id`),
  ADD KEY `fk_result_calc_by` (`calculated_by_user_id`),
  ADD KEY `idx_results_status` (`result_status_id`);

--
-- Indexes for table `student_credit_limits`
--
ALTER TABLE `student_credit_limits`
  ADD PRIMARY KEY (`credit_limit_id`),
  ADD UNIQUE KEY `uq_student_credit_limit` (`student_id`,`academic_year_id`,`semester_id`),
  ADD KEY `fk_credit_year` (`academic_year_id`),
  ADD KEY `fk_credit_semester` (`semester_id`),
  ADD KEY `fk_credit_approved_by` (`approved_by_user_id`);

--
-- Indexes for table `student_documents`
--
ALTER TABLE `student_documents`
  ADD PRIMARY KEY (`student_document_id`),
  ADD KEY `fk_stu_doc_student` (`student_id`),
  ADD KEY `fk_stu_doc_type` (`document_type_id`),
  ADD KEY `fk_stu_doc_verified_by` (`verified_by_user_id`);

--
-- Indexes for table `student_grade_components`
--
ALTER TABLE `student_grade_components`
  ADD PRIMARY KEY (`student_grade_component_id`),
  ADD UNIQUE KEY `uq_student_grade_component` (`student_course_registration_id`,`grade_component_id`),
  ADD KEY `fk_stu_grade_component` (`grade_component_id`),
  ADD KEY `fk_stu_grade_entered_by` (`entered_by_user_id`);

--
-- Indexes for table `student_statuses`
--
ALTER TABLE `student_statuses`
  ADD PRIMARY KEY (`student_status_id`),
  ADD UNIQUE KEY `status_code` (`status_code`);

--
-- Indexes for table `supplementary_exam_periods`
--
ALTER TABLE `supplementary_exam_periods`
  ADD PRIMARY KEY (`supplementary_exam_period_id`),
  ADD KEY `fk_supp_year` (`academic_year_id`),
  ADD KEY `fk_supp_semester` (`semester_id`);

--
-- Indexes for table `supplementary_exam_results`
--
ALTER TABLE `supplementary_exam_results`
  ADD PRIMARY KEY (`supplementary_exam_result_id`),
  ADD KEY `fk_supp_result_period` (`supplementary_exam_period_id`),
  ADD KEY `fk_supp_result_reg` (`student_course_registration_id`),
  ADD KEY `fk_supp_result_entered_by` (`entered_by_user_id`);

--
-- Indexes for table `system_modules`
--
ALTER TABLE `system_modules`
  ADD PRIMARY KEY (`module_id`),
  ADD UNIQUE KEY `module_code` (`module_code`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_user_created_by` (`created_by_user_id`),
  ADD KEY `idx_users_status` (`account_status_id`),
  ADD KEY `idx_users_employee` (`employee_id`),
  ADD KEY `fk_user_student` (`student_id`),
  ADD KEY `fk_user_board_member` (`board_member_id`);

--
-- Indexes for table `user_activity_logs`
--
ALTER TABLE `user_activity_logs`
  ADD PRIMARY KEY (`activity_log_id`),
  ADD KEY `fk_activity_user` (`user_id`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`user_role_id`),
  ADD UNIQUE KEY `uq_user_role` (`user_id`,`role_id`),
  ADD KEY `fk_user_role_role` (`role_id`),
  ADD KEY `fk_user_role_assigned_by` (`assigned_by_user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `academic_levels`
--
ALTER TABLE `academic_levels`
  MODIFY `academic_level_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `academic_programs`
--
ALTER TABLE `academic_programs`
  MODIFY `academic_program_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `academic_years`
--
ALTER TABLE `academic_years`
  MODIFY `academic_year_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `account_statuses`
--
ALTER TABLE `account_statuses`
  MODIFY `account_status_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `admission_applications`
--
ALTER TABLE `admission_applications`
  MODIFY `admission_application_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `appeal_statuses`
--
ALTER TABLE `appeal_statuses`
  MODIFY `appeal_status_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `applicants`
--
ALTER TABLE `applicants`
  MODIFY `applicant_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `approval_statuses`
--
ALTER TABLE `approval_statuses`
  MODIFY `approval_status_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  MODIFY `attendance_session_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_statuses`
--
ALTER TABLE `attendance_statuses`
  MODIFY `attendance_status_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `boards`
--
ALTER TABLE `boards`
  MODIFY `board_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `board_decisions`
--
ALTER TABLE `board_decisions`
  MODIFY `board_decision_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `board_decision_attachments`
--
ALTER TABLE `board_decision_attachments`
  MODIFY `attachment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `board_meetings`
--
ALTER TABLE `board_meetings`
  MODIFY `board_meeting_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `board_members`
--
ALTER TABLE `board_members`
  MODIFY `board_member_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `colleges`
--
ALTER TABLE `colleges`
  MODIFY `college_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `course_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `course_departments`
--
ALTER TABLE `course_departments`
  MODIFY `course_department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `course_instructors`
--
ALTER TABLE `course_instructors`
  MODIFY `course_instructor_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `course_offerings`
--
ALTER TABLE `course_offerings`
  MODIFY `course_offering_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `course_prerequisites`
--
ALTER TABLE `course_prerequisites`
  MODIFY `course_prerequisite_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `document_types`
--
ALTER TABLE `document_types`
  MODIFY `document_type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `employee_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `employee_positions`
--
ALTER TABLE `employee_positions`
  MODIFY `employee_position_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_statuses`
--
ALTER TABLE `employee_statuses`
  MODIFY `employee_status_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `employee_types`
--
ALTER TABLE `employee_types`
  MODIFY `employee_type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `employee_unit_assignments`
--
ALTER TABLE `employee_unit_assignments`
  MODIFY `assignment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `faculty_members`
--
ALTER TABLE `faculty_members`
  MODIFY `faculty_member_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `grade_appeals`
--
ALTER TABLE `grade_appeals`
  MODIFY `grade_appeal_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `grade_approvals`
--
ALTER TABLE `grade_approvals`
  MODIFY `grade_approval_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `grade_audit_logs`
--
ALTER TABLE `grade_audit_logs`
  MODIFY `grade_audit_log_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `grade_components`
--
ALTER TABLE `grade_components`
  MODIFY `grade_component_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `grading_policies`
--
ALTER TABLE `grading_policies`
  MODIFY `grading_policy_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `library_authors`
--
ALTER TABLE `library_authors`
  MODIFY `library_author_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `library_books`
--
ALTER TABLE `library_books`
  MODIFY `library_book_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `library_book_authors`
--
ALTER TABLE `library_book_authors`
  MODIFY `book_author_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `library_book_copies`
--
ALTER TABLE `library_book_copies`
  MODIFY `library_book_copy_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `library_borrowings`
--
ALTER TABLE `library_borrowings`
  MODIFY `library_borrowing_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `library_categories`
--
ALTER TABLE `library_categories`
  MODIFY `library_category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `login_audit_logs`
--
ALTER TABLE `login_audit_logs`
  MODIFY `login_audit_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `meeting_attendees`
--
ALTER TABLE `meeting_attendees`
  MODIFY `meeting_attendee_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `organizational_units`
--
ALTER TABLE `organizational_units`
  MODIFY `organizational_unit_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `organizational_unit_types`
--
ALTER TABLE `organizational_unit_types`
  MODIFY `unit_type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  MODIFY `token_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `permission_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT for table `positions`
--
ALTER TABLE `positions`
  MODIFY `position_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `program_courses`
--
ALTER TABLE `program_courses`
  MODIFY `program_course_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `registration_statuses`
--
ALTER TABLE `registration_statuses`
  MODIFY `registration_status_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `result_statuses`
--
ALTER TABLE `result_statuses`
  MODIFY `result_status_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `role_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `role_permission_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=90;

--
-- AUTO_INCREMENT for table `semesters`
--
ALTER TABLE `semesters`
  MODIFY `semester_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `student_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `student_academic_terms`
--
ALTER TABLE `student_academic_terms`
  MODIFY `student_academic_term_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_attendance`
--
ALTER TABLE `student_attendance`
  MODIFY `student_attendance_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `student_course_registrations`
--
ALTER TABLE `student_course_registrations`
  MODIFY `student_course_registration_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `student_course_results`
--
ALTER TABLE `student_course_results`
  MODIFY `student_course_result_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_credit_limits`
--
ALTER TABLE `student_credit_limits`
  MODIFY `credit_limit_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_documents`
--
ALTER TABLE `student_documents`
  MODIFY `student_document_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_grade_components`
--
ALTER TABLE `student_grade_components`
  MODIFY `student_grade_component_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_statuses`
--
ALTER TABLE `student_statuses`
  MODIFY `student_status_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `supplementary_exam_periods`
--
ALTER TABLE `supplementary_exam_periods`
  MODIFY `supplementary_exam_period_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplementary_exam_results`
--
ALTER TABLE `supplementary_exam_results`
  MODIFY `supplementary_exam_result_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_modules`
--
ALTER TABLE `system_modules`
  MODIFY `module_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `user_activity_logs`
--
ALTER TABLE `user_activity_logs`
  MODIFY `activity_log_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_roles`
--
ALTER TABLE `user_roles`
  MODIFY `user_role_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `academic_programs`
--
ALTER TABLE `academic_programs`
  ADD CONSTRAINT `fk_program_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`);

--
-- Constraints for table `admission_applications`
--
ALTER TABLE `admission_applications`
  ADD CONSTRAINT `fk_admission_applicant` FOREIGN KEY (`applicant_id`) REFERENCES `applicants` (`applicant_id`),
  ADD CONSTRAINT `fk_admission_decided_by` FOREIGN KEY (`decided_by_user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_admission_program` FOREIGN KEY (`academic_program_id`) REFERENCES `academic_programs` (`academic_program_id`),
  ADD CONSTRAINT `fk_admission_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`academic_year_id`);

--
-- Constraints for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  ADD CONSTRAINT `fk_att_session_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_att_session_faculty` FOREIGN KEY (`faculty_member_id`) REFERENCES `faculty_members` (`faculty_member_id`),
  ADD CONSTRAINT `fk_att_session_offering` FOREIGN KEY (`course_offering_id`) REFERENCES `course_offerings` (`course_offering_id`);

--
-- Constraints for table `boards`
--
ALTER TABLE `boards`
  ADD CONSTRAINT `fk_board_org_unit` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`organizational_unit_id`);

--
-- Constraints for table `board_decisions`
--
ALTER TABLE `board_decisions`
  ADD CONSTRAINT `fk_decision_meeting` FOREIGN KEY (`board_meeting_id`) REFERENCES `board_meetings` (`board_meeting_id`);

--
-- Constraints for table `board_decision_attachments`
--
ALTER TABLE `board_decision_attachments`
  ADD CONSTRAINT `fk_decision_attachment_decision` FOREIGN KEY (`board_decision_id`) REFERENCES `board_decisions` (`board_decision_id`),
  ADD CONSTRAINT `fk_decision_attachment_user` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `board_meetings`
--
ALTER TABLE `board_meetings`
  ADD CONSTRAINT `fk_meeting_board` FOREIGN KEY (`board_id`) REFERENCES `boards` (`board_id`),
  ADD CONSTRAINT `fk_meeting_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `board_members`
--
ALTER TABLE `board_members`
  ADD CONSTRAINT `fk_board_member_board` FOREIGN KEY (`board_id`) REFERENCES `boards` (`board_id`),
  ADD CONSTRAINT `fk_board_member_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`);

--
-- Constraints for table `colleges`
--
ALTER TABLE `colleges`
  ADD CONSTRAINT `fk_college_org_unit` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`organizational_unit_id`);

--
-- Constraints for table `course_departments`
--
ALTER TABLE `course_departments`
  ADD CONSTRAINT `fk_course_dept_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`),
  ADD CONSTRAINT `fk_course_dept_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`);

--
-- Constraints for table `course_instructors`
--
ALTER TABLE `course_instructors`
  ADD CONSTRAINT `fk_course_inst_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`),
  ADD CONSTRAINT `fk_course_inst_faculty` FOREIGN KEY (`faculty_member_id`) REFERENCES `faculty_members` (`faculty_member_id`);

--
-- Constraints for table `course_offerings`
--
ALTER TABLE `course_offerings`
  ADD CONSTRAINT `fk_offering_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`),
  ADD CONSTRAINT `fk_offering_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`),
  ADD CONSTRAINT `fk_offering_faculty` FOREIGN KEY (`faculty_member_id`) REFERENCES `faculty_members` (`faculty_member_id`),
  ADD CONSTRAINT `fk_offering_program` FOREIGN KEY (`academic_program_id`) REFERENCES `academic_programs` (`academic_program_id`),
  ADD CONSTRAINT `fk_offering_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`semester_id`),
  ADD CONSTRAINT `fk_offering_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`academic_year_id`);

--
-- Constraints for table `course_prerequisites`
--
ALTER TABLE `course_prerequisites`
  ADD CONSTRAINT `fk_prereq_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`),
  ADD CONSTRAINT `fk_prereq_min_status` FOREIGN KEY (`minimum_result_status_id`) REFERENCES `result_statuses` (`result_status_id`),
  ADD CONSTRAINT `fk_prereq_required_course` FOREIGN KEY (`prerequisite_course_id`) REFERENCES `courses` (`course_id`);

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `fk_department_college` FOREIGN KEY (`college_id`) REFERENCES `colleges` (`college_id`),
  ADD CONSTRAINT `fk_department_org_unit` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`organizational_unit_id`);

--
-- Constraints for table `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `fk_emp_org_unit` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`organizational_unit_id`),
  ADD CONSTRAINT `fk_emp_status` FOREIGN KEY (`employee_status_id`) REFERENCES `employee_statuses` (`employee_status_id`),
  ADD CONSTRAINT `fk_emp_type` FOREIGN KEY (`employee_type_id`) REFERENCES `employee_types` (`employee_type_id`);

--
-- Constraints for table `employee_positions`
--
ALTER TABLE `employee_positions`
  ADD CONSTRAINT `fk_emp_pos_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  ADD CONSTRAINT `fk_emp_pos_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`position_id`),
  ADD CONSTRAINT `fk_emp_pos_unit` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`organizational_unit_id`);

--
-- Constraints for table `employee_unit_assignments`
--
ALTER TABLE `employee_unit_assignments`
  ADD CONSTRAINT `fk_emp_unit_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  ADD CONSTRAINT `fk_emp_unit_unit` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`organizational_unit_id`);

--
-- Constraints for table `faculty_members`
--
ALTER TABLE `faculty_members`
  ADD CONSTRAINT `fk_faculty_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`);

--
-- Constraints for table `grade_appeals`
--
ALTER TABLE `grade_appeals`
  ADD CONSTRAINT `fk_appeal_reg` FOREIGN KEY (`student_course_registration_id`) REFERENCES `student_course_registrations` (`student_course_registration_id`),
  ADD CONSTRAINT `fk_appeal_reviewed_by` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_appeal_status` FOREIGN KEY (`appeal_status_id`) REFERENCES `appeal_statuses` (`appeal_status_id`),
  ADD CONSTRAINT `fk_appeal_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`);

--
-- Constraints for table `grade_approvals`
--
ALTER TABLE `grade_approvals`
  ADD CONSTRAINT `fk_approval_approved_by` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_approval_offering` FOREIGN KEY (`course_offering_id`) REFERENCES `course_offerings` (`course_offering_id`),
  ADD CONSTRAINT `fk_approval_status` FOREIGN KEY (`approval_status_id`) REFERENCES `approval_statuses` (`approval_status_id`),
  ADD CONSTRAINT `fk_approval_submitted_by` FOREIGN KEY (`submitted_by_user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `grade_audit_logs`
--
ALTER TABLE `grade_audit_logs`
  ADD CONSTRAINT `fk_grade_audit_grade` FOREIGN KEY (`student_grade_component_id`) REFERENCES `student_grade_components` (`student_grade_component_id`),
  ADD CONSTRAINT `fk_grade_audit_user` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `grade_components`
--
ALTER TABLE `grade_components`
  ADD CONSTRAINT `fk_grade_comp_offering` FOREIGN KEY (`course_offering_id`) REFERENCES `course_offerings` (`course_offering_id`);

--
-- Constraints for table `library_books`
--
ALTER TABLE `library_books`
  ADD CONSTRAINT `fk_book_category` FOREIGN KEY (`category_id`) REFERENCES `library_categories` (`library_category_id`);

--
-- Constraints for table `library_book_authors`
--
ALTER TABLE `library_book_authors`
  ADD CONSTRAINT `fk_book_author_author` FOREIGN KEY (`library_author_id`) REFERENCES `library_authors` (`library_author_id`),
  ADD CONSTRAINT `fk_book_author_book` FOREIGN KEY (`library_book_id`) REFERENCES `library_books` (`library_book_id`);

--
-- Constraints for table `library_book_copies`
--
ALTER TABLE `library_book_copies`
  ADD CONSTRAINT `fk_copy_book` FOREIGN KEY (`library_book_id`) REFERENCES `library_books` (`library_book_id`);

--
-- Constraints for table `library_borrowings`
--
ALTER TABLE `library_borrowings`
  ADD CONSTRAINT `fk_borrow_copy` FOREIGN KEY (`library_book_copy_id`) REFERENCES `library_book_copies` (`library_book_copy_id`),
  ADD CONSTRAINT `fk_borrow_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_borrow_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  ADD CONSTRAINT `fk_borrow_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`);

--
-- Constraints for table `login_audit_logs`
--
ALTER TABLE `login_audit_logs`
  ADD CONSTRAINT `fk_login_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `meeting_attendees`
--
ALTER TABLE `meeting_attendees`
  ADD CONSTRAINT `fk_attendee_meeting` FOREIGN KEY (`board_meeting_id`) REFERENCES `board_meetings` (`board_meeting_id`),
  ADD CONSTRAINT `fk_attendee_member` FOREIGN KEY (`board_member_id`) REFERENCES `board_members` (`board_member_id`);

--
-- Constraints for table `organizational_units`
--
ALTER TABLE `organizational_units`
  ADD CONSTRAINT `fk_org_unit_parent` FOREIGN KEY (`parent_unit_id`) REFERENCES `organizational_units` (`organizational_unit_id`),
  ADD CONSTRAINT `fk_org_unit_type` FOREIGN KEY (`unit_type_id`) REFERENCES `organizational_unit_types` (`unit_type_id`);

--
-- Constraints for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `permissions`
--
ALTER TABLE `permissions`
  ADD CONSTRAINT `fk_permission_module` FOREIGN KEY (`module_id`) REFERENCES `system_modules` (`module_id`);

--
-- Constraints for table `program_courses`
--
ALTER TABLE `program_courses`
  ADD CONSTRAINT `fk_prog_course_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`),
  ADD CONSTRAINT `fk_prog_course_level` FOREIGN KEY (`academic_level_id`) REFERENCES `academic_levels` (`academic_level_id`),
  ADD CONSTRAINT `fk_prog_course_program` FOREIGN KEY (`academic_program_id`) REFERENCES `academic_programs` (`academic_program_id`),
  ADD CONSTRAINT `fk_prog_course_semester` FOREIGN KEY (`recommended_semester_id`) REFERENCES `semesters` (`semester_id`);

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_role_perm_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`permission_id`),
  ADD CONSTRAINT `fk_role_perm_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`);

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `fk_student_admission` FOREIGN KEY (`admission_application_id`) REFERENCES `admission_applications` (`admission_application_id`),
  ADD CONSTRAINT `fk_student_level` FOREIGN KEY (`current_academic_level_id`) REFERENCES `academic_levels` (`academic_level_id`),
  ADD CONSTRAINT `fk_student_program` FOREIGN KEY (`academic_program_id`) REFERENCES `academic_programs` (`academic_program_id`),
  ADD CONSTRAINT `fk_student_status` FOREIGN KEY (`student_status_id`) REFERENCES `student_statuses` (`student_status_id`);

--
-- Constraints for table `student_academic_terms`
--
ALTER TABLE `student_academic_terms`
  ADD CONSTRAINT `fk_sat_level` FOREIGN KEY (`academic_level_id`) REFERENCES `academic_levels` (`academic_level_id`),
  ADD CONSTRAINT `fk_sat_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`semester_id`),
  ADD CONSTRAINT `fk_sat_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `fk_sat_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`academic_year_id`);

--
-- Constraints for table `student_attendance`
--
ALTER TABLE `student_attendance`
  ADD CONSTRAINT `fk_stu_att_session` FOREIGN KEY (`attendance_session_id`) REFERENCES `attendance_sessions` (`attendance_session_id`),
  ADD CONSTRAINT `fk_stu_att_status` FOREIGN KEY (`attendance_status_id`) REFERENCES `attendance_statuses` (`attendance_status_id`),
  ADD CONSTRAINT `fk_stu_att_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`);

--
-- Constraints for table `student_course_registrations`
--
ALTER TABLE `student_course_registrations`
  ADD CONSTRAINT `fk_reg_advisor` FOREIGN KEY (`advisor_user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_reg_offering` FOREIGN KEY (`course_offering_id`) REFERENCES `course_offerings` (`course_offering_id`),
  ADD CONSTRAINT `fk_reg_registered_by` FOREIGN KEY (`registered_by_user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_reg_result_status` FOREIGN KEY (`result_status_id`) REFERENCES `result_statuses` (`result_status_id`),
  ADD CONSTRAINT `fk_reg_status` FOREIGN KEY (`registration_status_id`) REFERENCES `registration_statuses` (`registration_status_id`),
  ADD CONSTRAINT `fk_reg_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`);

--
-- Constraints for table `student_course_results`
--
ALTER TABLE `student_course_results`
  ADD CONSTRAINT `fk_result_calc_by` FOREIGN KEY (`calculated_by_user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_result_reg` FOREIGN KEY (`student_course_registration_id`) REFERENCES `student_course_registrations` (`student_course_registration_id`),
  ADD CONSTRAINT `fk_result_status` FOREIGN KEY (`result_status_id`) REFERENCES `result_statuses` (`result_status_id`);

--
-- Constraints for table `student_credit_limits`
--
ALTER TABLE `student_credit_limits`
  ADD CONSTRAINT `fk_credit_approved_by` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_credit_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`semester_id`),
  ADD CONSTRAINT `fk_credit_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `fk_credit_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`academic_year_id`);

--
-- Constraints for table `student_documents`
--
ALTER TABLE `student_documents`
  ADD CONSTRAINT `fk_stu_doc_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `fk_stu_doc_type` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`document_type_id`),
  ADD CONSTRAINT `fk_stu_doc_verified_by` FOREIGN KEY (`verified_by_user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `student_grade_components`
--
ALTER TABLE `student_grade_components`
  ADD CONSTRAINT `fk_stu_grade_component` FOREIGN KEY (`grade_component_id`) REFERENCES `grade_components` (`grade_component_id`),
  ADD CONSTRAINT `fk_stu_grade_entered_by` FOREIGN KEY (`entered_by_user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_stu_grade_reg` FOREIGN KEY (`student_course_registration_id`) REFERENCES `student_course_registrations` (`student_course_registration_id`);

--
-- Constraints for table `supplementary_exam_periods`
--
ALTER TABLE `supplementary_exam_periods`
  ADD CONSTRAINT `fk_supp_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`semester_id`),
  ADD CONSTRAINT `fk_supp_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`academic_year_id`);

--
-- Constraints for table `supplementary_exam_results`
--
ALTER TABLE `supplementary_exam_results`
  ADD CONSTRAINT `fk_supp_result_entered_by` FOREIGN KEY (`entered_by_user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_supp_result_period` FOREIGN KEY (`supplementary_exam_period_id`) REFERENCES `supplementary_exam_periods` (`supplementary_exam_period_id`),
  ADD CONSTRAINT `fk_supp_result_reg` FOREIGN KEY (`student_course_registration_id`) REFERENCES `student_course_registrations` (`student_course_registration_id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_user_board_member` FOREIGN KEY (`board_member_id`) REFERENCES `board_members` (`board_member_id`),
  ADD CONSTRAINT `fk_user_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_user_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  ADD CONSTRAINT `fk_user_status` FOREIGN KEY (`account_status_id`) REFERENCES `account_statuses` (`account_status_id`),
  ADD CONSTRAINT `fk_user_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`);

--
-- Constraints for table `user_activity_logs`
--
ALTER TABLE `user_activity_logs`
  ADD CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `fk_user_role_assigned_by` FOREIGN KEY (`assigned_by_user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_user_role_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`),
  ADD CONSTRAINT `fk_user_role_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
