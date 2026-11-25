<?php

return [
    'unavailable' => 'Support is unavailable right now. Try again in a moment.',
    'scan_sent' => 'Reading sent to the central. The drafts show up in the help tree in a few minutes.',
    'scan_sent_no_ai' => 'Reading sent to the central, but AI is not available on your plan right now.',
    'scan_failed' => 'Could not reach the central. Check RASTRO_URL and RASTRO_KEY.',
    'guide_created' => 'Created :file at the project root.',
    'guide_exists' => ':file is already there. Use --force to replace it with this package\'s version.',
    'guide_next' => 'Now ask your coding agent: "Follow :file". It will write :output; after reviewing it, run php artisan rastro:help-push.',
    'push_missing' => ':file not found. Create the guide with php artisan :guide and ask your coding agent to write the tree.',
    'push_invalid' => ':file is not valid JSON with a "topics" list.',
    'push_refused' => 'The central refused the file. Fix it and send again:',
    'push_done' => 'Tree sent: :created new, :updated drafts updated, :kept already published left as they were. Review and publish them in the central\'s help editor.',
];
