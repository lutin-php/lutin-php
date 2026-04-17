<?php
// src/class_loader.php - Centralized class loader for both build and runtime

declare(strict_types=1);

return [
    'classes' => [
        'LutinConfig',
        'LutinAuth',
        'LutinFileManager',
    ],
    'agent_providers' => [
        'AnthropicAdapter',
        'OpenAIGenericAdapter',
        'AgentAdaptersCatalog',
        'AbstractLutinAdapter',
    ],
    'agents' => [
        'AgentTools',
        'AbstractLutinAgent',
        'LutinChatAgent',
        'LutinEditorAgent',
    ],
    'pages' => [
        'AbstractLutinPage',
        'LutinChatPage',
        'LutinEditorPage',
        'LutinConfigPage',
    ],
    'core' => [
        'LutinRouter',
        'LutinView',
    ],
];