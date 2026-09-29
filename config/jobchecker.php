<?php

return [
    // Path to the Claude Code CLI binary
    'claude_bin' => env('CLAUDE_BIN', 'claude'),
    // Model passed to `claude --model`
    'claude_model' => env('CLAUDE_MODEL', 'sonnet'),
    // Seconds before a single CLI call is killed
    'claude_timeout' => (int) env('CLAUDE_TIMEOUT', 600),
    // Seconds for a company-research call (agentic web search, usually 1-3 min)
    'research_timeout' => (int) env('CLAUDE_RESEARCH_TIMEOUT', 600),
    // Optional long-lived token (`claude setup-token`) — needed only if the
    // Keychain login is not reachable from the web-server context
    'claude_oauth_token' => env('CLAUDE_CODE_OAUTH_TOKEN'),
];
