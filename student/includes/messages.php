<?php
// student/includes/messages.php

if (!empty($errors)): ?>
    <div class="mb-4 p-4 bg-red-100 border-l-4 border-red-500 text-red-700">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-red-500" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x-circle"></use></svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium">
                    There were some issues with your request:
                </h3>
                <div class="mt-2 text-sm">
                    <ul role="list" class="list-disc pl-5 space-y-1">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="mb-4 p-4 bg-green-100 border-l-4 border-green-500 text-green-700">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-green-500" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-check-circle"></use></svg>
            </div>
            <div class="ml-3">
                <p class="text-sm">
                    <?= htmlspecialchars($success) ?>
                </p>
            </div>
        </div>
    </div>
<?php endif; ?>
