<?php
// tutor/api/assign_student.php
// DISABLED: tutors may not assign students to themselves. Student-tutor assignments
// are created only by an admin (admin/admin_users.php -> "assign_tutor", which records
// assigned_by_admin_id). This endpoint remains as a hard server-side block so any stale
// UI, bookmark, or script cannot create self-assignments.
session_start();
require_once '../auth_tutor_only.php';

header('Content-Type: application/json');
http_response_code(403);
echo json_encode([
    'success' => false,
    'message' => 'Students are assigned to tutors by an administrator. Please ask an admin to assign this student to you.'
]);
exit();
