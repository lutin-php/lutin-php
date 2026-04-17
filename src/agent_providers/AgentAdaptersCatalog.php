<?php
declare(strict_types=1);

class AgentAdaptersCatalog {
    public static function get(): array {
        return [
            'anthropic' => [
                'name' => 'Anthropic (Claude)',
                'builder' => fn($apiKey, $model) => new AnthropicAdapter($apiKey, $model),
            ],
            'openai' => [
                'name' => 'OpenAI (GPT)',
                'builder' => fn($apiKey, $model) => new OpenAIGenericAdapter('https://api.openai.com/v1/chat/completions', $apiKey, $model),
            ],
            'gemini' => [
                'name' => 'Gemini (Google)',
                'builder' => fn($apiKey, $model) => new OpenAIGenericAdapter('https://generativelanguage.googleapis.com/v1beta/openai/v1/chat/completions', $apiKey, $model),
            ],
            'github' => [
                'name' => 'GitHub Models',
                'builder' => fn($apiKey, $model) => new OpenAIGenericAdapter('https://models.inference.ai.azure.com/chat/completions', $apiKey, $model),
            ],
        ];
    }
}