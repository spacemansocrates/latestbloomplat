"""End-to-end HTTP tests against tests/tutor/prepare.py's disposable app and DB."""
from pathlib import Path
import base64, json, re, subprocess
import requests

ROOT = Path(__file__).resolve().parents[2]
RUN = Path(json.loads((ROOT / 'docs/tutor-fixes-baseline.json').read_text())['backup'].replace('KAMEHA~1', 'Kamehameha'))
APP = RUN / 'www' / 'tutsys'
BASE = 'http://127.0.0.1:8088/tutsys/'
MYSQL = ['C:/xampp/mysql/bin/mysql.exe', '--host=127.0.0.1', '--port=33316', '--user=root', '--batch', '--skip-column-names', 'tutsys_tutor_test']
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aMfsAAAAASUVORK5CYII=')

def db(query):
    return subprocess.run(MYSQL, input=query, capture_output=True, text=True, check=True).stdout.strip()

def check(condition, label):
    if not condition:
        raise AssertionError(label)
    print('PASS', label)

def login(email='tutor@example.test'):
    session = requests.Session()
    response = session.post(BASE + 'authenticate.php', data={'email': email, 'password': 'FixturePass123!'}, allow_redirects=False)
    check(response.status_code == 302, 'login: ' + email)
    return session

def csrf(html):
    found = re.search(r'name="csrf_token" value="([a-f0-9]+)"', html)
    check(found is not None, 'CSRF token rendered')
    return found.group(1)

def page(session, path):
    response = session.get(BASE + path, allow_redirects=False)
    check(response.status_code == 200, path + ' loads')
    if path.endswith('.php') and '/api/' not in path and 'download.php' not in path:
        check('</html>' in response.text.lower(), path + ' renders completely')
    return response

def mysql_number(query):
    return float(db(query))

db("""
UPDATE tutor_payout_settings SET hourly_rate=120,currency='ZAR' WHERE tutor_id=1;
UPDATE users SET first_name='Test',last_name='Tutor',email='tutor@example.test',phone_number='+27123456789',profile_photo_url='/old-install/uploads/avatars/tutor.png' WHERE id=1;
UPDATE messages SET is_read=0 WHERE sender_id=3 AND receiver_id=1;
UPDATE homework_submissions SET status='Submitted',grade=NULL,tutor_feedback=NULL,graded_at=NULL WHERE id=1;
DELETE FROM homework_submissions WHERE homework_id=5;
DROP TRIGGER IF EXISTS fixture_fail_avatar;
""")

s = login()
pages = {name: page(s, 'tutor/' + name + '.php').text for name in ['settings', 'dashboard', 'homework', 'students', 'messages', 'schedule', 'recordings']}

# Private contact data must not appear in tutor HTML or profile/message JSON.
for private_value in ['private-alice@example.test', 'private-bob@example.test', 'private-outside@example.test', '+27999999991', '+27999999992']:
    check(all(private_value not in html for html in pages.values()), 'student contact absent from tutor pages: ' + private_value)
profile = s.get(BASE + 'tutor/api/get_student_profile.php?student_id=3')
check(profile.status_code == 200 and profile.json()['success'], 'assigned student profile allowed')
check(not {'email', 'phone', 'phone_number', 'guardian_phone'} & profile.json()['student'].keys(), 'profile API omits contact fields')
check(s.get(BASE + 'tutor/api/get_student_profile.php?student_id=5').status_code == 403, 'other tutor student profile denied')
check(requests.get(BASE + 'tutor/api/get_student_profile.php?student_id=3').status_code == 403, 'unauthenticated profile denied')

# Earnings single source of truth and settings routing.
check('Total earned' in pages['dashboard'] and 'ZAR 300.00' in pages['dashboard'], 'header lifetime earnings equals status fixtures')
check('ZAR 300.00' in pages['settings'] or 'R300.00' in pages['settings'], 'earnings tab total matches header amount')
token = csrf(pages['settings'])
before = db("SELECT CONCAT(hourly_rate,'|',currency) FROM tutor_payout_settings WHERE tutor_id=1")
r = s.post(BASE + 'tutor/settings.php', data={'form_name':'profile','csrf_token':token,'full_name':'Test Tutor','email':'tutor@example.test','phone_number':'+27123456789','bio':'Test tutor'})
check(r.status_code == 200 and db("SELECT CONCAT(hourly_rate,'|',currency) FROM tutor_payout_settings WHERE tutor_id=1") == before, 'profile form preserves payout settings')
r = s.post(BASE + 'tutor/settings.php', data={'form_name':'prefs','csrf_token':token,'currency':'ZAR','timezone':'UTC','notify_new_booking':'1'})
check(r.status_code == 200 and mysql_number("SELECT hourly_rate FROM tutor_payout_settings WHERE tutor_id=1") == 120, 'preferences form preserves hourly rate')
r = s.post(BASE + 'tutor/settings.php', data={'form_name':'password','csrf_token':token,'current_password':'wrong','new_password':'Different123!','confirm_password':'Different123!'})
check(r.status_code == 200 and mysql_number("SELECT hourly_rate FROM tutor_payout_settings WHERE tutor_id=1") == 120, 'password form preserves hourly rate')
r = s.post(BASE + 'tutor/settings.php', data={'form_name':'earnings','csrf_token':token})
check(r.status_code == 200 and mysql_number("SELECT hourly_rate FROM tutor_payout_settings WHERE tutor_id=1") == 120, 'missing earnings values rejected without overwrite')

# Upload, replacement, invalid/oversized input, CSRF, rollback, and fresh-login persistence.
def upload(data, filename='avatar.png', content_type='image/png'):
    return s.post(BASE + 'tutor/settings.php', data={'form_name':'avatar','csrf_token':token}, files={'avatar':(filename,data,content_type)})
first = upload(PNG)
first_url = db("SELECT profile_photo_url FROM users WHERE id=1")
check(first.status_code == 200 and first_url.startswith('uploads/avatars/avatar_1_'), 'valid tutor avatar saved')
second = upload(PNG)
second_url = db("SELECT profile_photo_url FROM users WHERE id=1")
check(second.status_code == 200 and second_url != first_url, 'replacement gets unique cache-safe URL')
check('/tutsys/uploads/avatars/' + Path(second_url).name in second.text and '?v=' in second.text, 'replacement rendered from current installation with version')
for label, data, filename, ctype in [
    ('invalid type', b'not an image', 'bad.jpg', 'image/jpeg'),
    ('oversized image', b'0' * (5 * 1024 * 1024 + 1), 'large.png', 'image/png'),
]:
    response = upload(data, filename, ctype)
    check(response.status_code == 200 and db("SELECT profile_photo_url FROM users WHERE id=1") == second_url, label + ' preserves existing avatar')
bad_csrf = s.post(BASE + 'tutor/settings.php', data={'form_name':'avatar','csrf_token':'bad'}, files={'avatar':('avatar.png',PNG,'image/png')})
check(bad_csrf.status_code == 200 and db("SELECT profile_photo_url FROM users WHERE id=1") == second_url, 'invalid upload CSRF preserves avatar')
before_files = len(list((APP / 'uploads/avatars').glob('avatar_1_*')))
db("CREATE TRIGGER fixture_fail_avatar BEFORE UPDATE ON users FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure';")
failed = upload(PNG)
db("DROP TRIGGER fixture_fail_avatar;")
check(failed.status_code == 200 and 'existing picture is unchanged' in failed.text.lower(), 'database failure gives safe upload feedback')
check(db("SELECT profile_photo_url FROM users WHERE id=1") == second_url and len(list((APP / 'uploads/avatars').glob('avatar_1_*'))) == before_files, 'database failure rolls back only new file')
s.get(BASE + 'logout.php')
s = login()
check(Path(second_url).name in page(s, 'tutor/settings.php').text, 'avatar persists after logout/login')
s.get(BASE + 'logout.php')
db("DELETE FROM tutor_payout_settings WHERE tutor_id=2")
other = login('other@example.test')
other_settings = page(other, 'tutor/settings.php').text
check(Path(second_url).name not in other_settings, 'new account session does not retain previous avatar')
check('Total earned: Unavailable' in other_settings and '>Unavailable<' in other_settings, 'missing payout data is distinct from a genuine zero balance')
s = login()

# Photo resolution: installation-relative, legacy, basename, missing, invalid and external.
photo_code = f"""<?php
$_SERVER['SCRIPT_FILENAME']={json.dumps(str(APP / 'tutor/settings.php'))};
$_SERVER['SCRIPT_NAME']='/tutsys/tutor/settings.php'; $_SERVER['DOCUMENT_ROOT']={json.dumps(str(RUN / 'www'))}; $_SERVER['HTTP_HOST']='127.0.0.1:8088';
require {json.dumps(str(APP / 'utils/profile_photo.php'))};
echo json_encode([
 profile_photo_url('uploads/avatars/alice.png'), profile_photo_url('/old/uploads/avatars/alice.png'),
 profile_photo_url('alice.png'), profile_photo_url('/uploads/avatars/missing.png'),
 profile_photo_url('/uploads/avatars/../../.env'), profile_photo_url('javascript:alert(1)'),
 profile_photo_url('https://images.example.test/avatar.png'), resolve_photo(null,'Alice Learner')
]);"""
resolved = json.loads(subprocess.run(['C:/xampp/php/php.exe'], input=photo_code, capture_output=True, text=True, check=True).stdout)
check(all(value.startswith('/tutsys/uploads/avatars/alice.png?v=') for value in resolved[:3]), 'current, legacy and basename photos resolve within this install')
check(resolved[3] is None and resolved[4] is None and resolved[5] is None, 'missing and invalid local photos rejected')
check(resolved[6] == 'https://images.example.test/avatar.png' and resolved[7].startswith('data:image/svg+xml;base64,'), 'external photo and initials fallback supported')
alice_photo_fragment = '/tutsys/uploads/avatars/alice.png?v='
for page_name in ['dashboard', 'homework', 'students', 'messages', 'recordings', 'settings']:
    check(alice_photo_fragment in pages[page_name], 'resolved student photo rendered on ' + page_name)
schedule_feed = s.get(BASE + 'tutor/schedule.php', params={'ajax':'1','year':'2026','month':'9'})
check(schedule_feed.status_code == 200 and schedule_feed.json()['success'], 'schedule lesson feed loads')
check(any((item.get('profile_photo') or '').startswith(alice_photo_fragment) for item in schedule_feed.json()['data']), 'resolved student photo returned by schedule feed')

# Messages: student/admin roles, no contacts, automatic read, authorization.
conversation = s.get(BASE + 'tutor/api/messages_api.php?action=list_conversations').json()
roles = {item['name']: item['role'] for item in conversation['items']}
check(roles.get('Alice Learner') == 'Student' and roles.get('Test Administrator') == 'Administrator', 'conversation API returns student/admin roles')
check('email' not in json.dumps(conversation).lower() and 'private-alice' not in json.dumps(conversation), 'conversation API omits student email')
check(int(db("SELECT is_read FROM messages WHERE sender_id=3 AND receiver_id=1 LIMIT 1")) == 0, 'student message begins unread')
opened = s.get(BASE + 'tutor/api/messages_api.php?action=get_messages&other_id=3').json()
check(opened['success'] and int(db("SELECT is_read FROM messages WHERE sender_id=3 AND receiver_id=1 LIMIT 1")) == 1, 'opening conversation marks it read automatically')
check(s.get(BASE + 'tutor/api/messages_api.php?action=get_messages&other_id=6').json()['success'], 'administrator conversation allowed')
check(not s.get(BASE + 'tutor/api/messages_api.php?action=get_messages&other_id=5').json()['success'], 'unassigned student conversation denied')
check('See profile' in pages['messages'] and 'Mark as read' not in pages['messages'], 'tutor message action replaced')

# Submission viewer data, local fallback paths, missing file errors and cross-tutor denial.
for homework_id, submission_id, count in [(1,1,0),(2,2,1),(3,3,2)]:
    response = s.get(BASE + f'tutor/api/get_submission_details.php?homework_id={homework_id}&submission_id={submission_id}')
    check(response.status_code == 200 and response.json()['success'] and len(response.json()['attachments']) == count, f'submission {submission_id} details and file count')
check(s.get(BASE + 'tutor/api/get_submission_details.php?homework_id=2&submission_id=1').status_code == 403, 'mismatched homework/submission pair denied')
check(s.get(BASE + 'tutor/api/get_submission_details.php?homework_id=4&submission_id=4').status_code == 403, 'other tutor submission denied')
check(s.get(BASE + 'student/api/download.php?type=submission&id=1&disposition=inline').status_code == 200, 'legacy local submission falls back to root uploads')
check(s.get(BASE + 'student/api/download.php?type=submission&id=2&disposition=inline').status_code == 200, 'historical tutor/uploads submission fallback works')
missing = s.get(BASE + 'student/api/download.php?type=submission&id=3&disposition=inline')
check(missing.status_code == 404 and 'unavailable' in missing.text.lower(), 'missing attachment returns clear error')
check(s.get(BASE + 'student/api/download.php?type=submission&id=4').status_code == 404, 'other tutor download hidden')

# Dashboard selection, late queue, grading CSRF/zero and reopening.
dashboard = page(s, 'tutor/dashboard.php').text
check('homework.php?homework_id=1&amp;submission_id=1' in dashboard, 'dashboard grade link selects exact submission')
check('homework.php?homework_id=3&amp;submission_id=3' in dashboard, 'late submission appears in grading queue')
homework = page(s, 'tutor/homework.php').text
grade_token = csrf(homework)
graded = s.post(BASE + 'tutor/api/grade_submission.php', data={'submission_id':'1','grade':'0','feedback':'Valid zero','csrf_token':grade_token})
check(graded.status_code == 200 and graded.json()['success'], 'zero grade accepted')
check(db("SELECT CONCAT(grade,'|',status,'|',tutor_feedback) FROM homework_submissions WHERE id=1") == '0.00|Graded|Valid zero', 'zero grade and refreshed status persisted')
reopened = s.get(BASE + 'tutor/api/get_submission_details.php?homework_id=1&submission_id=1').json()
check(float(reopened['submission']['grade']) == 0 and reopened['submission']['tutor_feedback'] == 'Valid zero', 'zero grade and feedback returned on reopen')
invalid_grade = s.post(BASE + 'tutor/api/grade_submission.php', data={'submission_id':'1','grade':'101','feedback':'bad','csrf_token':grade_token})
check(invalid_grade.status_code == 422 and float(db("SELECT grade FROM homework_submissions WHERE id=1")) == 0, 'out-of-range grade rejected without overwrite')
check(s.post(BASE + 'tutor/api/grade_submission.php', data={'submission_id':'4','grade':'50','feedback':'bad','csrf_token':grade_token}).status_code == 403, 'other tutor grading denied')

# Submit a text-only fixture through the student endpoint, then view as tutor.
s.get(BASE + 'logout.php')
student = login('private-alice@example.test')
submitted = student.post(BASE + 'student/api/submit_homeworks.php', data={'homework_id':'5','message':'No attachment answer'})
check(submitted.status_code == 200 and submitted.json()['success'] and submitted.json()['attachments'] == [], 'student text-only submission succeeds')
s = login()
new_id = db("SELECT id FROM homework_submissions WHERE homework_id=5 AND student_id=3")
check(s.get(BASE + f'tutor/api/get_submission_details.php?homework_id=5&submission_id={new_id}').json()['submission']['content'] == 'No attachment answer', 'tutor can view new text-only submission')

# Rendered JavaScript parses. This catches the prior literal-newline syntax failure.
checked = 0
for name in ['settings','dashboard','homework','students','messages','schedule','recordings']:
    html = page(s, 'tutor/' + name + '.php').text
    for index, attrs_and_body in enumerate(re.findall(r'<script([^>]*)>(.*?)</script>', html, re.I | re.S)):
        attrs, body = attrs_and_body
        if 'src=' in attrs.lower() or not body.strip():
            continue
        result = subprocess.run(['node', '--check'], input=body, capture_output=True, text=True, encoding='utf-8')
        check(result.returncode == 0, f'rendered JavaScript syntax: {name} block {index}; {result.stderr[:120]}')
        checked += 1
for source in [ROOT/'tutor/js/avatar-upload.js', ROOT/'tutor/js/student-profile.js']:
    check(subprocess.run(['node','--check',str(source)], capture_output=True).returncode == 0, 'JavaScript syntax: ' + source.name)
    checked += 1
check(checked > 10, 'meaningful set of rendered/external JavaScript blocks checked')

# Administrator contact implementation remains present and was not edited.
admin_source = (ROOT / 'admin/admin_users.php').read_text(encoding='utf-8')
check('email' in admin_source.lower() and ('phone' in admin_source.lower() or 'guardian' in admin_source.lower()), 'administrator contact fields remain implemented')
print('ALL TUTOR REGRESSION CHECKS PASSED')
