<?php
declare(strict_types=1);

const AGENT_TOOLS = [
    'list_files' => [
        'name' => 'list_files',
        'description' => 'Lists files in a directory. Path is relative to project root (parent of web root). Use empty string "" for project root.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Directory path relative to project root (e.g., "src", "public", "docs")'],
            ],
            'required' => ['path'],
        ],
    ],
    'read_file' => [
        'name' => 'read_file',
        'description' => 'Reads a file. Path is relative to project root.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'File path relative to project root (e.g., "src/classes/MyClass.php")'],
            ],
            'required' => ['path'],
        ],
    ],
    'write_file' => [
        'name' => 'write_file',
        'description' => 'Writes or creates a file. Path is relative to project root. Cannot write to the lutin/ directory.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'File path relative to project root (e.g., "src/classes/MyClass.php")'],
                'content' => ['type' => 'string', 'description' => 'File content'],
            ],
            'required' => ['path', 'content'],
        ],
    ],
    'open_file_in_editor' => [
        'name' => 'open_file_in_editor',
        'description' => 'Opens a file in the editor. Use this when you want to show the user a specific file in the editor interface. The file will be loaded and displayed to the user.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'File path relative to project root (e.g., "src/classes/MyClass.php")'],
            ],
            'required' => ['path'],
        ],
    ],
    'search_remote_modules' => [
        'name' => 'search_remote_modules',
        'description' => 'Search available Lutin modules from the remote repository. Can be filtered by a search query.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Optional: A search query to filter modules by name or description.'],
            ],
        ],
    ],
];