<section id="tab-editor">
  <!-- 3-column layout: File Explorer | Editor | AI Helper -->
  <div class="editor-layout">
    
    <!-- Left: File Explorer -->
    <aside class="editor-sidebar" id="file-explorer">
      <header class="sidebar-header">
        <strong>📁 Files</strong>
      </header>
      <div class="file-tree-container">
        <div id="file-tree-root"></div>
      </div>
    </aside>

    <!-- Middle: Editor -->
    <div class="editor-main">
      <header class="editor-toolbar">
        <div class="filename-input-wrapper">
          <input 
            type="text" 
            id="editor-filename" 
            class="filename-input" 
            placeholder="Enter file path..."
            autocomplete="off"
          >
          <div id="filename-autocomplete" class="autocomplete-dropdown"></div>
        </div>
        <button id="save-btn" class="btn-primary" disabled>💾 Save</button>
      </header>
      <div class="editor-container">
        <!-- CodeMirror for code files (PHP, JS, CSS, etc.) -->
        <div id="codemirror-container"></div>
        <!-- TinyMCE for HTML files -->
        <div id="tinymce-container" style="display: none;">
          <textarea id="tinymce-editor"></textarea>
        </div>
      </div>
    </div>

    <!-- Right: AI Helper Panel -->
    <aside class="editor-ai-panel" id="ai-helper">
      <header class="sidebar-header">
        <strong>🤖 AI Helper</strong>
      </header>
      <div class="ai-panel-content">
        <div class="ai-context-info">
          <small id="ai-context-file">No file context</small>
        </div>
        <textarea 
          id="editor-ai-prompt" 
          class="ai-prompt-input" 
          placeholder="Ask AI to help with the current file..."
          rows="6"
        ></textarea>
        <button id="editor-ai-submit" class="btn-primary btn-full">Ask AI</button>
        <div id="editor-ai-response" class="ai-response-container"></div>
      </div>
    </aside>

  </div>
</section>

<style>
  /* Editor 3-column layout */
  #tab-editor .editor-layout {
    display: grid;
    grid-template-columns: 260px 1fr 320px;
    gap: 0;
    height: calc(100vh - 80px);
    min-height: 600px;
    border: 1px solid var(--muted-border-color);
    border-radius: 8px;
    overflow: hidden;
  }

  /* Sidebars styling */
  #tab-editor .editor-sidebar,
  #tab-editor .editor-ai-panel {
    background: var(--card-background-color);
    display: flex;
    flex-direction: column;
  }

  #tab-editor .editor-sidebar {
    border-right: 1px solid var(--muted-border-color);
  }

  #tab-editor .editor-ai-panel {
    border-left: 1px solid var(--muted-border-color);
  }

  /* Sidebar headers */
  #tab-editor .sidebar-header {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--muted-border-color);
    background: var(--background-color);
  }

  /* File tree container */
  #tab-editor .file-tree-container {
    flex: 1;
    overflow: auto;
    padding: 0.5rem;
  }

  /* File tree items */
  #tab-editor .file-entry {
    padding: 0.25rem 0.5rem;
    cursor: pointer;
    border-radius: 4px;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
  }

  #tab-editor .file-entry:hover {
    background: var(--primary-focus);
  }

  #tab-editor .file-entry.active {
    background: var(--primary);
    color: white;
  }

  #tab-editor .file-entry .icon {
    font-size: 1rem;
  }

  #tab-editor .file-entry.dir .icon {
    color: #f0c040;
  }

  #tab-editor .file-entry.file .icon {
    color: #60a0ff;
  }

  #tab-editor .file-children {
    margin-left: 1rem;
    border-left: 1px solid var(--muted-border-color);
    padding-left: 0.5rem;
  }

  /* Main editor area */
  #tab-editor .editor-main {
    display: flex;
    flex-direction: column;
    background: var(--background-color);
  }

  #tab-editor .editor-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--muted-border-color);
    background: var(--card-background-color);
  }

  #tab-editor .filename-input-wrapper {
    position: relative;
    flex: 1;
    margin-right: 1rem;
  }

  #tab-editor .filename-input {
    width: 100%;
    font-family: monospace;
    font-size: 0.9rem;
    margin: 0;
    padding: 0.5rem 0.75rem;
  }

  #tab-editor .autocomplete-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    max-height: 300px;
    overflow-y: auto;
    background: var(--card-background-color);
    background-color: var(--card-background-color);
    border: 1px solid var(--muted-border-color);
    border-top: none;
    border-radius: 0 0 4px 4px;
    z-index: 100;
    display: none;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  }

  /* Force background for dark mode compatibility */
  [data-theme="dark"] #tab-editor .autocomplete-dropdown {
    background: #1a1a1a;
    background-color: #1a1a1a;
  }

  [data-theme="light"] #tab-editor .autocomplete-dropdown {
    background: #ffffff;
    background-color: #ffffff;
  }

  #tab-editor .autocomplete-dropdown.active {
    display: block;
  }

  #tab-editor .autocomplete-item {
    padding: 0.5rem 0.75rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    background: inherit;
  }

  #tab-editor .autocomplete-item:hover,
  #tab-editor .autocomplete-item.selected {
    background: var(--primary-focus);
    background-color: var(--primary-focus);
  }

  [data-theme="dark"] #tab-editor .autocomplete-item.selected,
  [data-theme="dark"] #tab-editor .autocomplete-item:hover {
    background: #2d4a6f;
    background-color: #2d4a6f;
  }

  #tab-editor .autocomplete-item .icon {
    font-size: 0.9rem;
  }

  #tab-editor .autocomplete-item .path {
    color: var(--muted-color);
    font-size: 0.8rem;
    margin-left: auto;
  }

  #tab-editor .autocomplete-no-results {
    padding: 0.75rem;
    color: var(--muted-color);
    font-style: italic;
    text-align: center;
  }

  #tab-editor .editor-container {
    flex: 1;
    overflow: hidden;
    position: relative;
  }

  #tab-editor #codemirror-container {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
  }

  #tab-editor #codemirror-container .CodeMirror {
    height: 100%;
    font-family: 'Monaco', 'Menlo', 'Consolas', monospace;
    font-size: 0.9rem;
  }

  /* TinyMCE container */
  #tab-editor #tinymce-container {
    height: 100%;
  }

  #tab-editor #tinymce-container .tox {
    border: none !important;
  }

  #tab-editor #tinymce-container .tox-edit-area {
    background: var(--background-color) !important;
  }

  /* AI Panel */
  #tab-editor .ai-panel-content {
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    flex: 1;
    overflow: auto;
  }

  #tab-editor .ai-context-info {
    padding: 0.5rem;
    background: var(--background-color);
    border-radius: 4px;
    font-size: 0.8rem;
  }

  #tab-editor .ai-context-info small {
    color: var(--muted-color);
  }

  #tab-editor .ai-prompt-input {
    width: 100%;
    resize: vertical;
    font-family: inherit;
    font-size: 0.9rem;
  }

  #tab-editor .ai-response-container {
    flex: 1;
    overflow: auto;
    font-size: 0.85rem;
    line-height: 1.5;
    white-space: pre-wrap;
    word-wrap: break-word;
  }

  #tab-editor .ai-response-container:empty::before {
    content: "AI response will appear here...";
    color: var(--muted-color);
    font-style: italic;
  }

  #tab-editor .ai-response-container.loading::before {
    content: "🤔 Thinking...";
    color: var(--primary);
  }

  #tab-editor .btn-full {
    width: 100%;
  }

  /* Responsive adjustments */
  @media (max-width: 1200px) {
    #tab-editor .editor-layout {
      grid-template-columns: 220px 1fr 280px;
    }
  }

  @media (max-width: 900px) {
    #tab-editor .editor-layout {
      grid-template-columns: 200px 1fr;
    }
    #tab-editor .editor-ai-panel {
      display: none;
    }
  }
</style>
