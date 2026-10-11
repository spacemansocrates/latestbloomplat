(() => {
  const input = document.getElementById('avatar-file');
  if (!input) return;
  let previewUrl;
  input.addEventListener('change', () => {
    const wrap = document.getElementById('avatar-preview-wrap');
    const error = document.getElementById('avatar-preview-error');
    const preview = document.getElementById('avatar-preview');
    wrap.hidden = true;
    error.textContent = '';
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    const file = input.files[0];
    if (!file) return;
    if (!['image/jpeg', 'image/png'].includes(file.type) || file.size > 5 * 1024 * 1024) {
      error.textContent = 'Choose a JPG or PNG picture up to 5 MB.';
      input.value = '';
      return;
    }
    preview.onerror = () => {
      wrap.hidden = true;
      input.value = '';
      error.textContent = 'This picture could not be opened. Choose another JPG or PNG.';
    };
    preview.onload = () => { wrap.hidden = false; };
    previewUrl = URL.createObjectURL(file);
    preview.src = previewUrl;
  });
})();
