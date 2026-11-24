<?php

declare(strict_types=1);

/**
 * Browser module: interactive sessions (the agent drives one browser context per conversation).
 *
 * A config file replaces the module's default file as a whole: keep every key.
 */
return [
    // false: no session or design tool is offered and no session is opened
    'enabled' => false,
    // characters one typed text may have (the renderer refuses more as well)
    'maxTypedTextCharacters' => 200,
    // characters of a snapshot, a changed region or a design summary in one tool answer
    'maxAnswerCharacters' => 6000,
    // days a closed session and its event log are kept (app:agent-browser:purge-sessions); 0 = kept without a time limit
    'retentionDays' => 90,
];
