<?php
declare(strict_types=1);

class LutinView {
    private LutinConfig $config;
    private LutinAuth $auth;

    public function __construct(LutinConfig $config, LutinAuth $auth) {
        $this->config = $config;
        $this->auth = $auth;
    }

    public function renderSetupWizard(): void {
        $this->renderLayout('setup', function() {
            echo $this->getViewContent('setup_wizard');
        });
    }

    public function renderLogin(): void {
        $this->renderLayout('login', function() {
            echo $this->getViewContent('login');
        });
    }

    public function renderApp(): void {
        $this->renderLayout('chat', function() {
            echo $this->getViewContent('tab_chat');
            echo $this->getViewContent('tab_editor');
            echo $this->getViewContent('tab_config');
        });
    }

    public function renderTemplateSelection(): void {
        $this->renderLayout('templates', function() {
            echo $this->getViewContent('tab_templates');
        });
    }

    /**
     * Outputs the layout wrapper and calls $contentCallback to emit tab content.
     */
    private function renderLayout(string $activeTab, callable $contentCallback): void {
        $csrfToken = $this->auth->getCsrfToken();
        $siteTitle = 'Website Editor';

        $jsConfig = [
            'provider' => $this->config->getProvider(),
            'model' => $this->config->getModel(),
            'siteUrl' => $this->config->getSiteUrl(),
        ];

        ob_start();
        $contentCallback();
        $tabContent = ob_get_clean();

        $appJs = $this->getViewContent('app');

        // Get layout content
        $layoutContent = $this->getViewContent('layout');

        // Parse and render layout
        eval('?>' . $layoutContent);
    }

    /**
     * Gets view content (from constant or file).
     */
    private function getViewContent(string $name): string {
        // Check if we're in compiled mode (constants defined)
        $constName = 'LUTIN_VIEW_' . strtoupper($name);
        if ($name === 'app') {
            $constName = 'LUTIN_JS';
        }

        if (defined($constName)) {
            return constant($constName);
        }

        // Fall back to file loading for dev mode
        $filePath = __DIR__ . '/../views/';
        if ($name === 'app') {
            $filePath .= 'app.js';
        } else {
            $filePath .= str_replace('_', '_', $name) . '.php';
        }

        if (file_exists($filePath)) {
            return file_get_contents($filePath);
        }

        return '';
    }
}
