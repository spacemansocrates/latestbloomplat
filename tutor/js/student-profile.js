(() => {
  const modal = document.getElementById('viewProfileModal');
  const content = document.getElementById('profile-content');
  const loading = document.getElementById('profile-loading');
  const error = document.getElementById('profile-error');
  const save = document.getElementById('save-notes-btn');
  let sequence = 0;
  let previousFocus;
  const text = (id, value) => { document.getElementById(id).textContent = value; };
  const close = () => {
    sequence++;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    modal.removeAttribute('data-state');
    previousFocus?.focus();
  };
  window.openStudentProfile = async studentId => {
    const request = ++sequence;
    previousFocus = document.activeElement;
    save.dataset.studentId = '';
    save.disabled = true;
    content.classList.add('hidden');
    error.classList.add('hidden');
    loading.classList.remove('hidden');
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    modal.setAttribute('data-state', 'open');
    modal.querySelector('.modal-panel').setAttribute('data-state', 'open');
    modal.querySelector('.modal-close-btn').focus();
    try {
      const res = await fetch(`api/get_student_profile.php?student_id=${encodeURIComponent(studentId)}`, {credentials: 'same-origin'});
      const data = await res.json();
      if (request !== sequence) return;
      if (!res.ok || !data.success) throw new Error(data.message || 'Could not load this profile.');
      const {student, stats, topics, lessons} = data;
      const avatar = document.getElementById('profile-avatar');
      const fallback = '../assets/avatar.php?s=80&text=' + encodeURIComponent(student.initials || '?');
      avatar.onerror = () => { avatar.onerror = null; avatar.src = fallback; };
      avatar.src = student.profile_photo || fallback;
      text('profile-name', student.name);
      text('overall-score', stats.overall_score == null ? 'Not available' : Math.round(stats.overall_score) + '%');
      text('completed-count', stats.completed_lessons ?? 0);
      text('upcoming-count', stats.upcoming_lessons ?? 0);
      const pct = Math.max(0, Math.min(100, Math.round(stats.progress_pct || 0)));
      document.getElementById('progress-bar').style.width = pct + '%';
      text('progress-label', stats.progress_pct == null ? 'No progress recorded' : pct + '% complete');
      text('recent-topic', 'Most recent: ' + (stats.most_recent_topic || 'Not available'));
      const topicWrap = document.getElementById('topics-wrap');
      topicWrap.replaceChildren();
      (topics.length ? topics.slice(0, 10) : ['No topics recorded yet']).forEach(topic => {
        const chip = document.createElement('span');
        chip.className = 'text-xs px-2 py-1 rounded-full bg-indigo-500/10';
        chip.textContent = topic;
        topicWrap.append(chip);
      });
      const history = document.getElementById('lesson-history');
      history.replaceChildren();
      if (!lessons.length) history.textContent = 'No lessons yet';
      lessons.forEach(lesson => {
        const row = document.createElement('div');
        row.className = 'py-3 border-b border-slate-500/20';
        const title = document.createElement('p');
        title.className = 'font-medium';
        title.textContent = lesson.subject;
        const detail = document.createElement('p');
        detail.className = 'text-sm';
        detail.textContent = [lesson.topic, lesson.date, lesson.status].filter(Boolean).join(' | ');
        row.append(title, detail);
        history.append(row);
      });
      document.getElementById('tutorNotes').value = student.notes || '';
      save.dataset.studentId = String(student.id);
      save.disabled = false;
      content.classList.remove('hidden');
    } catch (e) {
      if (request !== sequence) return;
      error.textContent = e instanceof SyntaxError ? 'The profile could not be loaded. Please close and try again.' : (e.message || 'Could not load this profile. Please try again.');
      error.classList.remove('hidden');
    } finally {
      if (request === sequence) loading.classList.add('hidden');
    }
  };
  document.querySelectorAll('.view-profile-btn').forEach(btn => btn.addEventListener('click', () => window.openStudentProfile(btn.dataset.studentId)));
  modal.querySelectorAll('.modal-close-btn').forEach(btn => btn.addEventListener('click', close));
  modal.addEventListener('click', e => { if (e.target === modal) close(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });
  save.addEventListener('click', async () => {
    const studentId = save.dataset.studentId;
    if (!studentId || save.disabled) return;
    const request = sequence;
    save.disabled = true;
    try {
      const res = await fetch('api/save_student_notes.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({student_id: studentId, notes: document.getElementById('tutorNotes').value})
      });
      const data = await res.json();
      if (!res.ok || !data.success) throw new Error(data.message || 'Could not save notes.');
    } catch (e) {
      if (request === sequence) { error.textContent = e.message || 'Could not save notes.'; error.classList.remove('hidden'); }
    } finally { if (request === sequence) save.disabled = false; }
  });
})();
