"""Prepare a disposable copy and synthetic DB on an already isolated MariaDB port 33316.
Never reads .env, copies customer uploads, starts daemons, or connects to the configured app DB.
"""
from pathlib import Path
import base64, json, shutil, subprocess

ROOT = Path(__file__).resolve().parents[2]
RUN = Path(json.loads((ROOT / 'docs/tutor-fixes-baseline.json').read_text())['backup'])
APP = RUN / 'www' / 'tutsys'
APP.mkdir(parents=True, exist_ok=True)
for source in ROOT.rglob('*'):
    rel = source.relative_to(ROOT)
    if any(part in {'node_modules', 'vendor', 'uploads', 'graphify-out', '.git', '.codex', '.agents'} for part in rel.parts):
        continue
    if source.is_file() and source.name != '.env':
        target = APP / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source, target)
shutil.copytree(ROOT / 'vendor', APP / 'vendor', dirs_exist_ok=True)
(APP / '.env').write_text('DB_HOST=127.0.0.1\nDB_PORT=33316\nDB_NAME=tutsys_tutor_test\nDB_USER=root\nDB_PASSWORD=\n')
MYSQL = ['C:/xampp/mysql/bin/mysql.exe', '--host=127.0.0.1', '--port=33316', '--user=root', '--batch']
def sql(query):
    return subprocess.run(MYSQL, input=query, capture_output=True, text=True, check=True).stdout
if not sql("SHOW DATABASES LIKE 'tutsys_tutor_test'").strip():
    sql('CREATE DATABASE tutsys_tutor_test CHARACTER SET utf8mb4; USE tutsys_tutor_test;\n' + (ROOT / 'tests/tutor/schema.sql').read_text())
    helper = RUN / 'seed-passwords.php'
    helper.write_text("<?php $pdo = new PDO('mysql:host=127.0.0.1;port=33316;dbname=tutsys_tutor_test','root',''); $q=$pdo->prepare('UPDATE users SET password_hash=?'); $q->execute([password_hash('FixturePass123!', PASSWORD_DEFAULT)]);")
    subprocess.run(['C:/xampp/php/php.exe', str(helper)], check=True)
png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aMfsAAAAASUVORK5CYII=')
for name in ['tutor.png', 'alice.png']:
    target = APP / 'uploads/avatars' / name
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(png)
for name in ['uploads/submissions/single.pdf', 'tutor/uploads/submissions/multiple.pdf', 'uploads/homework/assignment.pdf', 'uploads/submissions/private.pdf']:
    target = APP / name
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(b'%PDF-1.4\nSynthetic attachment fixture.\n%%EOF')
print(APP)
