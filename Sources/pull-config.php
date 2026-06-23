<?php
// pull.php config — edit values below or delete this file to re-run the setup form.
// Keep this file in the same directory as pull.php. It is auto-preserved on every pull.
//
// SECRETS COME FROM THE ENVIRONMENT — never commit a token here (C2). Set
// GITHUB_TOKEN (PAT) and KRASKI_PULL_SECRET (URL secret) in the host env / a
// non-committed include. pull.php also reads GITHUB_TOKEN directly.

return [
    'repo'       => 'dansury/neuropro',
    'branch'     => 'main',
    'subdir'     => '.',
    'secret'     => (string) (\getenv('KRASKI_PULL_SECRET') ?: ''),
    'gh_token'   => (string) (\getenv('GITHUB_TOKEN') ?: ''),
    'keep_files' => ['pull.php', 'pull-config.php'],
    'timezone'   => 'Europe/Moscow',
];
