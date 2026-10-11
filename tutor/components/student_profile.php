    <!-- Modal 2: View Student Profile -->
    <div id="viewProfileModal" class="fixed inset-0 bg-black/70 flex items-center justify-center p-4 z-50 hidden modal-backdrop" aria-hidden="true">
      <div class="card-base-sub rounded-xl shadow-xl w-full max-w-3xl p-8 relative modal-panel" role="dialog" aria-modal="true" aria-label="Student profile" style="max-height:90vh;overflow-y:auto">
        <button class="absolute top-4 right-4 text-gray-400 hover:text-white modal-close-btn" aria-label="Close">
          <svg width="24" height="24" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
        </button>

        <!-- LOADING OVERLAY -->
        <div id="profile-loading" class="hidden absolute inset-0 bg-gray-900/70 backdrop-blur-sm rounded-xl flex items-center justify-center">
            <div class="flex items-center gap-3 text-gray-200">
                <span class="spinner"></span>
                <span>Loading profile…</span>
            </div>
        </div>

        <!-- CONTENT -->
        <p id="profile-error" role="alert" class="hidden my-6 text-red-600 dark:text-red-300"></p>
        <div id="profile-content">
          <!-- Header -->
          <div class="flex items-center gap-5 mb-6">
            <img id="profile-avatar" alt="" class="rounded-full flex-shrink-0 w-20 h-20 object-cover skeleton">
            <div>
              <h3 id="profile-name" class="text-2xl font-bold">—</h3>
            </div>
          </div>

          <!-- Stats -->
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-gray-800 rounded-lg p-4">
              <p class="text-sm text-gray-400">Overall Score</p>
              <p id="overall-score" class="text-2xl font-extrabold text-white mt-1">—</p>
              <p class="text-xs text-gray-500">Avg grade or attendance</p>
            </div>
            <div class="bg-gray-800 rounded-lg p-4">
              <p class="text-sm text-gray-400">Completed Lessons</p>
              <p id="completed-count" class="text-2xl font-extrabold text-white mt-1">0</p>
              <p class="text-xs text-gray-500">All-time with you</p>
            </div>
            <div class="bg-gray-800 rounded-lg p-4">
              <p class="text-sm text-gray-400">Upcoming Lessons</p>
              <p id="upcoming-count" class="text-2xl font-extrabold text-white mt-1">0</p>
              <p class="text-xs text-gray-500">Next scheduled</p>
            </div>
          </div>

          <!-- Progress -->
          <div class="mb-6">
            <div class="flex items-center justify-between mb-2">
              <h4 class="text-base font-semibold">Progress</h4>
              <span id="progress-label" class="text-sm text-gray-400">—</span>
            </div>
            <div class="w-full bg-gray-800 rounded-full h-3 overflow-hidden">
              <div id="progress-bar" class="bg-indigo-500 h-3" style="width:0%"></div>
            </div>
            <p class="text-xs text-gray-500 mt-2">Based on active package(s)</p>
          </div>

          <!-- Topics -->
          <div class="mb-6">
            <div class="flex items-center justify-between">
              <h4 class="text-base font-semibold">Topics Covered</h4>
              <div id="recent-topic" class="text-xs text-emerald-300 bg-emerald-500/10 border border-emerald-500/20 px-2 py-1 rounded-full">Most recent: —</div>
            </div>
            <div id="topics-wrap" class="flex flex-wrap gap-2 mt-3">
              <!-- chips go here -->
            </div>
          </div>

          <!-- Recent Lessons -->
          <div class="mb-6 card-base">
            <h4 class="text-base font-semibold mb-3">Previous Lessons</h4>
            <div id="lesson-history" class="space-y-2 max-h-52 overflow-y-auto pr-2">
              <!-- lessons go here -->
            </div>
          </div>

          <!-- Tutor private notes -->
          <div>
            <label for="tutorNotes" class="block text-sm font-semibold text-gray-300 mb-2">Tutor Notes</label>
            <textarea id="tutorNotes" rows="4" class="input-field" placeholder="Add private notes about this student..."></textarea>
          </div>

          <div class="flex justify-end gap-3 pt-6 mt-6 border-t border-gray-700">
            <button type="button" class="py-2.5 px-5 bg-gray-700 text-white font-semibold rounded-lg hover:bg-gray-600 modal-close-btn">Close</button>
            <button id="save-notes-btn" type="button" class="py-2.5 px-5 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-500 inline-flex items-center gap-2">
              <span class="btn-label">Save Notes</span>
              <span class="spinner hidden" aria-hidden="true"></span>
            </button>
          </div>
        </div>
      </div>
    </div>

<script src="js/student-profile.js"></script>
