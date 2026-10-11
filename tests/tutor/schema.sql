-- Synthetic schema only. Used on a separate loopback MariaDB instance; no production dump or triggers.
CREATE TABLE users (id INT PRIMARY KEY AUTO_INCREMENT, first_name VARCHAR(100), last_name VARCHAR(100), email VARCHAR(200) UNIQUE, phone_number VARCHAR(40), guardian_phone VARCHAR(40), password_hash VARCHAR(255), role VARCHAR(30), is_active INT DEFAULT 1, profile_photo_url TEXT, timezone VARCHAR(100) DEFAULT 'UTC', currency_code VARCHAR(10) DEFAULT 'USD', created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE tutor_details (user_id INT PRIMARY KEY, bio TEXT, google_meet_link TEXT);
CREATE TABLE user_settings (user_id INT PRIMARY KEY, timezone VARCHAR(100), notify_new_booking INT DEFAULT 1, notify_cancellation INT DEFAULT 1, notify_reminder_24h INT DEFAULT 0);
CREATE TABLE tutor_payout_settings (tutor_id INT PRIMARY KEY, hourly_rate DECIMAL(10,2), currency VARCHAR(10), updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE tutor_student_assignments (id INT PRIMARY KEY AUTO_INCREMENT, tutor_id INT, student_id INT, start_date DATE, end_date DATE);
CREATE TABLE subjects (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(100), curriculum VARCHAR(100), year_level INT);
CREATE TABLE tutor_subjects (tutor_id INT, subject_id INT);
CREATE TABLE lessons (id INT PRIMARY KEY AUTO_INCREMENT, tutor_id INT, student_id INT NULL, subject_id INT, scheduled_start_time DATETIME, scheduled_end_time DATETIME, status VARCHAR(40), meeting_url TEXT, tutor_notes TEXT, student_join_time DATETIME NULL, tutor_join_time DATETIME NULL, suppress_catchup INT DEFAULT 0, actual_start_time DATETIME NULL, actual_end_time DATETIME NULL, lesson_type VARCHAR(20) DEFAULT 'Normal');
CREATE TABLE homework (id INT PRIMARY KEY AUTO_INCREMENT, lesson_id INT NULL, tutor_id INT, student_id INT, title VARCHAR(255), description TEXT, due_date DATETIME, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE homework_submissions (id INT PRIMARY KEY AUTO_INCREMENT, homework_id INT, student_id INT, submission_date DATETIME, content TEXT, status VARCHAR(30), grade DECIMAL(5,2) NULL, tutor_feedback TEXT, graded_at DATETIME NULL, UNIQUE(homework_id,student_id));
CREATE TABLE homework_attachments (id INT PRIMARY KEY AUTO_INCREMENT, homework_id INT, file_name TEXT, file_url TEXT, uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE submission_attachments (id INT PRIMARY KEY AUTO_INCREMENT, submission_id INT, file_name TEXT, file_url TEXT, uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE messages (id INT PRIMARY KEY AUTO_INCREMENT, sender_id INT, receiver_id INT, content TEXT, is_read INT DEFAULT 0, sent_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE notifications (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT, type VARCHAR(50), message TEXT, related_entity_id INT, is_read INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE tutor_student_notes (id INT PRIMARY KEY AUTO_INCREMENT, tutor_id INT, student_id INT, notes TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(tutor_id,student_id));
CREATE TABLE topics (id INT PRIMARY KEY AUTO_INCREMENT, subject_id INT, name VARCHAR(100));
CREATE TABLE subtopics (id INT PRIMARY KEY AUTO_INCREMENT, topic_id INT, name VARCHAR(100));
CREATE TABLE lesson_topics_covered (lesson_id INT, topic_id INT);
CREATE TABLE lesson_subtopics_covered (lesson_id INT, subtopic_id INT);
CREATE TABLE lesson_recordings (id INT PRIMARY KEY AUTO_INCREMENT, lesson_id INT, file_url TEXT, playback_url TEXT, file_size_bytes BIGINT, duration_seconds INT, recorded_at DATETIME, status VARCHAR(30));
CREATE TABLE tutor_availability (id INT PRIMARY KEY AUTO_INCREMENT, tutor_id INT, day_of_week INT, start_time TIME, end_time TIME);
CREATE TABLE tutor_metrics (tutor_id INT, student_retention_rate DECIMAL(6,2), homework_feedback_avg_hours DECIMAL(6,2));
CREATE TABLE lesson_ratings (id INT PRIMARY KEY AUTO_INCREMENT, lesson_id INT, student_id INT, rating INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE learning_materials (id INT PRIMARY KEY AUTO_INCREMENT, title VARCHAR(255), description TEXT, material_type VARCHAR(40), file_url TEXT, external_url TEXT, curriculum VARCHAR(100), grade_level INT, subject_id INT NULL, is_published INT DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE ui_banners (id INT PRIMARY KEY AUTO_INCREMENT, page_key VARCHAR(50), audience VARCHAR(30), title VARCHAR(255), message TEXT, image_url TEXT, alt_text TEXT, cta_label VARCHAR(100), cta_url TEXT, is_active INT, starts_at DATETIME NULL, ends_at DATETIME NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE catchup_requests (id INT PRIMARY KEY AUTO_INCREMENT, original_lesson_id INT, student_id INT, tutor_id INT, reason VARCHAR(40), status VARCHAR(30));
INSERT INTO users (id,first_name,last_name,email,phone_number,guardian_phone,password_hash,role,profile_photo_url) VALUES
 (1,'Test','Tutor','tutor@example.test','+27123456789',NULL,'','Tutor','/old-install/uploads/avatars/tutor.png'),
 (2,'Other','Tutor','other@example.test',NULL,NULL,'','Tutor',NULL),
 (3,'Alice','Learner','private-alice@example.test','+27999999991','+27999999992','','Student','/tutsys2/uploads/avatars/alice.png'),
 (4,'Bob','Learner','private-bob@example.test','+27999999993','+27999999994','','Student',NULL),
 (5,'Outside','Learner','private-outside@example.test','+27999999995','+27999999996','','Student',NULL),
 (6,'Test','Administrator','admin@example.test',NULL,NULL,'','Administrator',NULL);
INSERT INTO tutor_payout_settings VALUES (1,120,'ZAR',NOW()),(2,75,'USD',NOW());
INSERT INTO tutor_details VALUES (1,'Test tutor',NULL),(2,'Other tutor',NULL);
INSERT INTO user_settings (user_id,timezone) VALUES (1,'UTC'),(2,'UTC');
INSERT INTO tutor_student_assignments (tutor_id,student_id,start_date) VALUES (1,3,CURDATE()),(1,4,CURDATE()),(2,5,CURDATE());
INSERT INTO subjects VALUES (1,'Mathematics','Test curriculum',8);
INSERT INTO tutor_subjects VALUES (1,1),(2,1);
INSERT INTO topics VALUES (1,1,'Fractions');
INSERT INTO lessons (id,tutor_id,student_id,subject_id,scheduled_start_time,scheduled_end_time,status,student_join_time,tutor_join_time) VALUES
 (1,1,3,1,DATE_SUB(NOW(),INTERVAL 2 DAY),DATE_ADD(DATE_SUB(NOW(),INTERVAL 2 DAY),INTERVAL 90 MINUTE),'Completed',NOW(),NOW()),
 (2,1,NULL,1,DATE_SUB(NOW(),INTERVAL 1 DAY),DATE_ADD(DATE_SUB(NOW(),INTERVAL 1 DAY),INTERVAL 60 MINUTE),'Completed',NULL,NOW()),
 (3,1,4,1,DATE_SUB(NOW(),INTERVAL 1 DAY),DATE_ADD(DATE_SUB(NOW(),INTERVAL 1 DAY),INTERVAL 90 MINUTE),'NoShow',NULL,NOW()),
 (4,1,3,1,DATE_ADD(NOW(),INTERVAL 1 DAY),DATE_ADD(NOW(),INTERVAL 25 HOUR),'Scheduled',NULL,NULL),
 (5,1,4,1,DATE_ADD(NOW(),INTERVAL 2 DAY),DATE_ADD(NOW(),INTERVAL 49 HOUR),'CancelledByTutor',NULL,NULL),
 (6,2,5,1,NOW(),DATE_ADD(NOW(),INTERVAL 1 HOUR),'Completed',NOW(),NOW()),
 (7,1,3,1,DATE_ADD(NOW(),INTERVAL 2 DAY),DATE_ADD(NOW(),INTERVAL 49 HOUR),'InProgress',NOW(),NOW()),
 (8,1,4,1,DATE_ADD(NOW(),INTERVAL 3 DAY),DATE_ADD(NOW(),INTERVAL 73 HOUR),'CancelledByStudent',NULL,NULL);
INSERT INTO lesson_topics_covered VALUES (1,1);
INSERT INTO homework (id,tutor_id,student_id,title,description,due_date) VALUES
 (1,1,3,'Text only','Explain fractions. <script>not markup</script>',DATE_ADD(NOW(),INTERVAL 1 DAY)),
 (2,1,3,'Single attachment','Read the assignment file.',DATE_ADD(NOW(),INTERVAL 1 DAY)),
 (3,1,4,'Multiple attachments','Show your work.',DATE_SUB(NOW(),INTERVAL 1 DAY)),
 (4,2,5,'Private assignment','Other tutor only.',DATE_ADD(NOW(),INTERVAL 1 DAY)),
 (5,1,3,'New submission test','Submission endpoint fixture.',DATE_ADD(NOW(),INTERVAL 1 DAY));
INSERT INTO homework_submissions (id,homework_id,student_id,submission_date,content,status) VALUES
 (1,1,3,NOW(),'Text only <img src=x onerror=alert(1)>','Submitted'),
 (2,2,3,NOW(),'One file','Submitted'),
 (3,3,4,NOW(),'Multiple files','Late'),
 (4,4,5,NOW(),'Private submission','Submitted');
INSERT INTO submission_attachments (id,submission_id,file_name,file_url) VALUES
 (1,2,'work.pdf','/legacy/uploads/submissions/single.pdf'),
 (2,3,'<img src=x onerror=alert(1)>.pdf','/uploads/submissions/multiple.pdf'),
 (3,3,'missing.pdf','/uploads/submissions/missing.pdf'),
 (4,4,'private.pdf','/uploads/submissions/private.pdf');
INSERT INTO homework_attachments (id,homework_id,file_name,file_url) VALUES (1,2,'Assignment.pdf','/uploads/homework/assignment.pdf');
INSERT INTO messages (sender_id,receiver_id,content) VALUES (3,1,'Student fixture message'),(6,1,'Administrator fixture message'),(5,2,'Private other-tutor message');
INSERT INTO lesson_recordings (lesson_id,file_url,playback_url,file_size_bytes,duration_seconds,recorded_at,status) VALUES (1,'/uploads/lesson_recordings/test.mp4','/uploads/lesson_recordings/test.mp4',100,60,NOW(),'Completed');
