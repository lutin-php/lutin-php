// ── STATE ─────────────────────────────────────────────────────────────────────
const state = {
  csrfToken: document.querySelector('meta[name="lutin-token"]')?.content ?? '',
  currentFile: null,        // relative path of open file
  currentEditor: null,      // 'codemirror', 'tinymce', or 'prism'
  cmEditor: null,           // CodeMirror instance
  tinymceEditor: null,      // TinyMCE instance
  chatHistory: [],          // [{role, content}] accumulated for context
  editorChatHistory: [],    // Separate history for editor AI helper
  isStreaming: false,       // true while SSE is open
};

// ── TAB DETECTION ─────────────────────────────────────────────────────────────
function getCurrentTab() {
  // First try URL hash (strip query params)
  if (location.hash) {
    const hash = location.hash.slice(1);
    return hash.split('?')[0];
  }
  // Fall back to visible tab
  const visibleSection = document.querySelector('section[style*="display: block"]') ||
                          document.querySelector('section');
  if (visibleSection) {
    return visibleSection.id.replace('tab-', '');
  }
  return 'chat'; // Default
}

/**
 * Parse query parameters from the URL hash (e.g., #editor?file=path&other=value)
 * Returns URLSearchParams object or null if no params
 */
function getHashParams() {
  if (!location.hash) return null;
  const hash = location.hash.slice(1);
  const queryIndex = hash.indexOf('?');
  if (queryIndex === -1) return null;
  return new URLSearchParams(hash.slice(queryIndex + 1));
}

/**
 * Update the URL hash with query parameters while keeping the tab
 */
function setHashParams(params) {
  const tab = getCurrentTab();
  const urlParams = new URLSearchParams();
  
  for (const [key, value] of Object.entries(params)) {
    if (value !== null && value !== undefined) {
      urlParams.set(key, value);
    }
  }
  
  const queryString = urlParams.toString();
  const newHash = queryString ? `${tab}?${queryString}` : tab;
  
  // Use replaceState to avoid creating history entries for internal state changes
  history.replaceState(null, '', `#${newHash}`);
}

// ── UTILS ─────────────────────────────────────────────────────────────────────
async function apiPost(action, body) {
  const tab = getCurrentTab();
  const response = await fetch(`?action=${action}&tab=${tab}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Lutin-Token': state.csrfToken,
    },
    body: JSON.stringify(body),
  });
  const data = await response.json();
  // Include HTTP status in response
  data.httpStatus = response.status;
  return data;
}

async function apiGet(action, params = {}) {
  const tab = getCurrentTab();
  const query = new URLSearchParams({ action, tab, ...params }).toString();
  const response = await fetch(`?${query}`, {
    headers: {
      'X-Lutin-Token': state.csrfToken,
    },
  });
  const data = await response.json();
  // Include HTTP status in response
  data.httpStatus = response.status;
  return data;
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

function showToast(message, type = 'info') {
  const className = `toast-${type}`;
  const toast = document.createElement('div');
  toast.className = `toast ${className}`;
  toast.textContent = message;
  toast.style.cssText = `
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 1rem;
    background: ${type === 'error' ? '#dc3545' : type === 'success' ? '#28a745' : '#17a2b8'};
    color: white;
    border-radius: 4px;
    z-index: 9999;
    max-width: 300px;
  `;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 3000);
}

// ── TABS ──────────────────────────────────────────────────────────────────────
function initTabs() {
  // Check if we're on the login/setup/template page (single page view)
  // The main app has #tab-chat, #tab-editor, #tab-config sections
  // Single-page views only have one section (login/setup/templates)
  const hasMainAppTabs = document.getElementById('tab-chat') !== null;
  if (!hasMainAppTabs) {
    // On single-page views, always show the single section that exists
    // and ignore any URL hash
    const section = document.querySelector('section');
    if (section) section.style.display = 'block';
    return;
  }

  function showTab(tabName) {
    // Strip query parameters from tabName (e.g., "editor?file=path" -> "editor")
    const cleanTabName = tabName.split('?')[0];
    
    // Hide all sections
    document.querySelectorAll('section').forEach(s => s.style.display = 'none');

    // Show selected section
    const section = document.getElementById(`tab-${cleanTabName}`);
    if (section) section.style.display = 'block';

    // Update nav styling if nav exists
    const navLinks = document.querySelectorAll('nav a');
    if (navLinks.length > 0) {
      navLinks.forEach(a => {
        a.removeAttribute('aria-current');
        if (a.href.includes(`#${tabName}`)) {
          a.setAttribute('aria-current', 'page');
        }
      });
    }
  }

  // Handle hash changes - only show a different tab if hash is explicitly set
  window.addEventListener('hashchange', () => {
    if (location.hash) {
      const hash = location.hash.slice(1).split('?')[0];
      showTab(hash);
    }
  });

  // Initial show - only if hash is explicitly set in URL
  // Otherwise, trust the CSS to show the correct initial tab (from PHP)
  if (location.hash) {
    const initialTab = location.hash.slice(1).split('?')[0];
    showTab(initialTab);
  }
  // If no hash, update nav styling to match the visible tab (from CSS)
  else {
    const visibleTab = document.querySelector('section[style*="display: block"]') ||
                        document.querySelector('section');
    if (visibleTab) {
      const tabName = visibleTab.id.replace('tab-', '');
      const navLinks = document.querySelectorAll('nav a');
      if (navLinks.length > 0) {
        navLinks.forEach(a => {
          a.removeAttribute('aria-current');
          if (a.href.includes(`#${tabName}`)) {
            a.setAttribute('aria-current', 'page');
          }
        });
      }
    }
  }
}

// ── CHAT ──────────────────────────────────────────────────────────────────────
function initChat() {
  const chatForm = document.getElementById('chat-form');
  const chatInput = document.getElementById('chat-input');
  const chatMessages = document.getElementById('chat-messages');

  if (!chatForm) return;

  chatForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const message = chatInput.value.trim();
    if (!message) return;

    state.chatHistory.push({ role: 'user', content: message });
    appendMessage('user', message);
    chatInput.value = '';

    state.isStreaming = true;
    // Show loading indicator
    const loadingId = showLoadingIndicator();
    await openChatStream(message, loadingId);
    hideLoadingIndicator(loadingId);
    state.isStreaming = false;
  });
}

function showLoadingIndicator() {
  const chatMessages = document.getElementById('chat-messages');
  if (!chatMessages) return null;
  
  const id = 'loading-' + Date.now();
  const loading = document.createElement('article');
  loading.id = id;
  loading.className = 'message message--assistant message--loading';
  loading.innerHTML = `
    <div class="message__content">
      <span class="loading-dots">Thinking<span>.</span><span>.</span><span>.</span></span>
    </div>
  `;
  chatMessages.appendChild(loading);
  chatMessages.scrollTop = chatMessages.scrollHeight;
  return id;
}

function hideLoadingIndicator(id) {
  if (!id) return;
  const el = document.getElementById(id);
  if (el) el.remove();
}

function appendMessage(role, content) {
  const chatMessages = document.getElementById('chat-messages');
  if (!chatMessages) return;

  const article = document.createElement('article');
  article.className = `message message--${role}`;
  article.innerHTML = `<div class="message__content">${escapeHtml(content)}</div>`;
  chatMessages.appendChild(article);
  chatMessages.scrollTop = chatMessages.scrollHeight;

  return article;
}

async function openChatStream(userText, loadingId) {
  let assistantBubble = null;
  let assistantText = '';
  let receivedAnyData = false;
  
  try {
    const response = await fetch('?action=chat&tab=chat', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Lutin-Token': state.csrfToken,
      },
      body: JSON.stringify({
        message: userText,
        history: state.chatHistory,
      }),
    });

    if (!response.ok) {
      hideLoadingIndicator(loadingId);
      const errorText = await response.text();
      let errorMsg = 'Error sending message';
      try {
        const errorJson = JSON.parse(errorText);
        errorMsg = errorJson.error || errorMsg;
      } catch {}
      appendErrorMessage(errorMsg);
      return;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      buffer += decoder.decode(value, { stream: true });
      const lines = buffer.split('\n\n');
      buffer = lines.pop();

      for (const line of lines) {
        if (!line.startsWith('data: ')) continue;

        const jsonStr = line.slice(6);
        if (jsonStr === '[DONE]') continue;

        try {
          const event = JSON.parse(jsonStr);
          receivedAnyData = true;
          
          // Hide loading indicator once we start receiving data
          if (assistantBubble === null && loadingId) {
            hideLoadingIndicator(loadingId);
          }
          
          const result = handleSseEvent(event, assistantBubble, (bubble) => {
            assistantBubble = bubble;
          }, (text) => {
            assistantText += text;
          });
          if (result && result.bubble) {
            assistantBubble = result.bubble;
          }
        } catch (e) {
          console.error('Failed to parse SSE event:', e, line);
        }
      }
    }

    // Hide loading indicator if still showing (no data received or done)
    hideLoadingIndicator(loadingId);
    
    // If no data was received at all, show an error
    if (!receivedAnyData) {
      appendErrorMessage('No response received from the AI. Please check your API configuration.');
      return;
    }

    if (assistantText) {
      state.chatHistory.push({ role: 'assistant', content: assistantText });
    }
  } catch (error) {
    hideLoadingIndicator(loadingId);
    console.error('Chat stream error:', error);
    appendErrorMessage('Stream error: ' + error.message);
  }
}

function appendErrorMessage(message) {
  const chatMessages = document.getElementById('chat-messages');
  if (!chatMessages) return;
  
  const article = document.createElement('article');
  article.className = 'message message--assistant message--error';
  article.innerHTML = `
    <div class="message__content" style="color: #dc3545;">
      <strong>❌ Error:</strong> ${escapeHtml(message)}
      <br><small>Check your <a href="#config" onclick="showTab('config')">API configuration</a> and try again.</small>
    </div>
  `;
  chatMessages.appendChild(article);
  chatMessages.scrollTop = chatMessages.scrollHeight;
}

function handleSseEvent(event, bubbleEl, setBubble, appendText) {
  const chatMessages = document.getElementById('chat-messages');
  let result = { bubble: bubbleEl };

  if (event.type === 'text') {
    if (!bubbleEl) {
      bubbleEl = document.createElement('article');
      bubbleEl.className = 'message message--assistant';
      bubbleEl.innerHTML = '<div class="message__content"></div>';
      chatMessages.appendChild(bubbleEl);
      setBubble(bubbleEl);
      result.bubble = bubbleEl;
    }
    const content = bubbleEl.querySelector('.message__content');
    content.textContent += event.delta;
    appendText(event.delta);
    chatMessages.scrollTop = chatMessages.scrollHeight;
  } else if (event.type === 'error') {
    // Display error message to user
    showToast('Chat error: ' + event.message, 'error');
    if (!bubbleEl) {
      bubbleEl = document.createElement('article');
      bubbleEl.className = 'message message--assistant message--error';
      bubbleEl.innerHTML = '<div class="message__content"></div>';
      chatMessages.appendChild(bubbleEl);
      setBubble(bubbleEl);
      result.bubble = bubbleEl;
    }
    const content = bubbleEl.querySelector('.message__content');
    content.innerHTML = '❌ <strong>Error:</strong> ' + escapeHtml(event.message) + 
      '<br><small>Check your <a href="#config" onclick="showTab(\'config\')">API configuration</a></small>';
    appendText('');
    chatMessages.scrollTop = chatMessages.scrollHeight;
  } else if (event.type === 'tool_start' || event.type === 'tool_call') {
    if (!bubbleEl) {
      bubbleEl = document.createElement('article');
      bubbleEl.className = 'message message--assistant';
      bubbleEl.innerHTML = '<div class="message__content"></div>';
      chatMessages.appendChild(bubbleEl);
      setBubble(bubbleEl);
      result.bubble = bubbleEl;
    }
    // Create or update tool call details element
    const detailsId = 'tool-' + event.id;
    let details = document.getElementById(detailsId);
    if (!details) {
      details = document.createElement('details');
      details.id = detailsId;
      details.dataset.status = 'running';
      details.innerHTML = `
        <summary>🔧 ${escapeHtml(event.name)} (${event.id})</summary>
        <pre class="tool-input">${escapeHtml(JSON.stringify(event.input || {}, null, 2))}</pre>
        <pre class="tool-result" style="display:none; background:#1a472a; color:#90ee90; padding:0.5rem;"></pre>
      `;
      bubbleEl.appendChild(details);
    }
    chatMessages.scrollTop = chatMessages.scrollHeight;
  } else if (event.type === 'tool_result') {
    // Update existing tool call with result
    const detailsId = 'tool-' + event.id;
    const details = document.getElementById(detailsId);
    if (details) {
      details.dataset.status = 'done';
      const resultPre = details.querySelector('.tool-result');
      if (resultPre) {
        resultPre.style.display = 'block';
        resultPre.textContent = 'Result: ' + escapeHtml(event.result);
      }
      const summary = details.querySelector('summary');
      if (summary) {
        summary.textContent = summary.textContent.replace('🔧', '✅');
      }
    }
  } else if (event.type === 'stop') {
    // Conversation ended normally, nothing special to do
    console.log('Chat stopped:', event.stop_reason);
  }
  
  return result;
}

// ── EDITOR ────────────────────────────────────────────────────────────────────
function initEditor() {
  const cmContainer = document.getElementById('codemirror-container');
  if (cmContainer) {
    state.cmEditor = CodeMirror(cmContainer, {
      lineNumbers: true,
      theme: 'default',
      mode: 'php',
      indentUnit: 4,
      tabSize: 4,
      indentWithTabs: false,
      lineWrapping: false,
      value: '// Select a file to edit',
    });
  }

  // Initialize TinyMCE config (will be applied when needed)
  initJodit();

  const saveBtn = document.getElementById('save-btn');
  if (saveBtn) {
    saveBtn.addEventListener('click', saveFile);
  }
  
  // Check if there's a file parameter in URL and auto-open it
  const hashParams = getHashParams();
  if (hashParams) {
    const filePath = hashParams.get('file');
    if (filePath) {
      // Small delay to ensure editor is fully initialized
      setTimeout(() => openFile(filePath), 100);
    }
  }
}

function initJodit() {
  // Jodit will be initialized when needed
  state.joditEditor = null;
}

function switchEditor(editorType) {
  // Hide all editors
  const cmContainer = document.getElementById('codemirror-container');
  const tinymceContainer = document.getElementById('tinymce-container');
  
  if (cmContainer) cmContainer.style.display = 'none';
  if (tinymceContainer) tinymceContainer.style.display = 'none';
  
  // Destroy Jodit instance when switching away from it to free memory
  if (state.currentEditor === 'tinymce' && state.joditEditor && editorType !== 'tinymce') {
    state.joditEditor.destruct();
    state.joditEditor = null;
  }
  
  state.currentEditor = editorType;
  
  // Show selected editor
  if (editorType === 'tinymce') {
    if (tinymceContainer) tinymceContainer.style.display = 'block';
  } else {
    if (cmContainer) cmContainer.style.display = 'block';
    // Refresh CodeMirror when showing it (in case container was hidden)
    if (state.cmEditor) {
      state.cmEditor.refresh();
    }
  }
}

function getEditorContent() {
  if (state.currentEditor === 'tinymce') {
    return state.joditEditor ? state.joditEditor.value : '';
  }
  return state.cmEditor ? state.cmEditor.getValue() : '';
}

function setEditorContent(content, path) {
  const ext = getFileExtension(path);
  
  if (isHtmlFile(ext)) {
    // Use TinyMCE for HTML files
    switchEditor('tinymce');
    
    if (state.joditEditor) {
      state.joditEditor.value = content;
    } else if (typeof Jodit !== 'undefined') {
      // Recreate textarea if it was destroyed
      const container = document.getElementById('tinymce-container');
      if (container && !document.getElementById('tinymce-editor')) {
        container.innerHTML = '<textarea id="tinymce-editor"></textarea>';
      }
      
      // Jodit not initialized yet, init now
      const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
      state.joditEditor = Jodit.make('#tinymce-editor', {
        iframe: true,
        height: '100%',
        theme: isDark ? 'dark' : 'default',
        toolbar: true,
        buttons: [
          'source', '|',
          'bold', 'italic', 'underline', 'strikethrough', '|',
          'ul', 'ol', '|',
          'font', 'fontsize', 'brush', 'paragraph', '|',
          'image', 'link', 'table', '|',
          'align', 'undo', 'redo', '|',
          'hr', 'eraser', 'copyformat', '|',
          'symbol', 'fullsize'
        ],
      });
      state.joditEditor.value = content;
    }
  } else {
    // Use CodeMirror for all other files (PHP, JS, CSS, TXT, MD, etc.)
    switchEditor('codemirror');
    const mode = detectMode(path);
    // Clear before setting mode to avoid tokenization errors
    state.cmEditor.setValue('');
    state.cmEditor.setOption('mode', mode);
    // Use a small timeout to ensure mode is ready
    requestAnimationFrame(() => {
      state.cmEditor.setValue(content);
    });
  }
}

function getFileExtension(path) {
  const match = path.match(/\.([^/.]+)$/);
  return match ? match[1].toLowerCase() : '';
}

function isHtmlFile(ext) {
  return ['html', 'htm'].includes(ext);
}



async function openFile(path) {
  try {
    const result = await apiGet('read', { path });
    if (!result.ok) {
      showToast('Error reading file: ' + result.error, 'error');
      return;
    }

    state.currentFile = path;
    
    // Update URL with file path for persistence on refresh
    setHashParams({ file: path });
    
    // Set content in appropriate editor
    setEditorContent(result.data.content, path);

    const filenameInput = document.getElementById('editor-filename');
    if (filenameInput) filenameInput.value = path;
    
    const saveBtn = document.getElementById('save-btn');
    if (saveBtn) saveBtn.disabled = false;
    
    // Update AI helper context
    updateEditorAiContext(path);
    
    // Clear any autocomplete
    hideFilenameAutocomplete();
  } catch (error) {
    showToast('Error: ' + error.message, 'error');
  }
}

async function openFileFromInput() {
  const filenameInput = document.getElementById('editor-filename');
  if (!filenameInput) return;
  
  const path = filenameInput.value.trim();
  if (!path) {
    showToast('Please enter a file path', 'warning');
    return;
  }
  
  await openFile(path);
}

async function saveFile() {
  if (!state.currentFile) {
    showToast('No file selected', 'warning');
    return;
  }

  try {
    const content = getEditorContent();
    const result = await apiPost('write', {
      path: state.currentFile,
      content: content,
    });

    if (result.ok) {
      showToast('File saved!', 'success');
    } else {
      showToast('Error: ' + result.error, 'error');
    }
  } catch (error) {
    showToast('Error: ' + error.message, 'error');
  }
}

function detectMode(path) {
  if (path.endsWith('.php')) return 'php';
  if (path.endsWith('.js')) return 'javascript';
  if (path.endsWith('.css')) return 'css';
  if (path.endsWith('.html') || path.endsWith('.htm')) return 'htmlmixed';
  if (path.endsWith('.json')) return 'application/json';
  if (path.endsWith('.xml')) return 'xml';
  if (path.endsWith('.sql')) return 'sql';
  if (path.endsWith('.md')) return 'markdown';
  if (path.endsWith('.txt')) return 'text';
  return 'text';
}

// ── FILE TREE ─────────────────────────────────────────────────────────────────
function initFileTree() {
  const fileTreeRoot = document.getElementById('file-tree-root');
  if (!fileTreeRoot) return;

  loadDir('', fileTreeRoot);
}

async function loadDir(path, containerEl) {
  try {
    const result = await apiGet('list', { path });
    if (!result.ok) {
      showToast('Error loading files: ' + result.error, 'error');
      return;
    }

    containerEl.innerHTML = '';
    
    // Sort entries: directories first, then files alphabetically
    const sortedEntries = result.data.sort((a, b) => {
      if (a.type === b.type) {
        return a.name.localeCompare(b.name);
      }
      return a.type === 'dir' ? -1 : 1;
    });
    
    for (const entry of sortedEntries) {
      renderFileEntry(entry, containerEl);
    }
  } catch (error) {
    showToast('Error: ' + error.message, 'error');
  }
}

function renderFileEntry(entry, containerEl) {
  const div = document.createElement('div');
  div.className = 'file-entry-wrapper';

  if (entry.type === 'dir') {
    // Directory with expandable children
    const dirEntry = document.createElement('div');
    dirEntry.className = 'file-entry dir';
    dirEntry.dataset.path = entry.path;
    dirEntry.innerHTML = `<span class="icon">📁</span><span class="name">${escapeHtml(entry.name)}</span>`;
    
    const childrenContainer = document.createElement('div');
    childrenContainer.className = 'file-children';
    childrenContainer.style.display = 'none';
    
    let loaded = false;
    dirEntry.addEventListener('click', async () => {
      const isExpanded = childrenContainer.style.display !== 'none';
      
      if (isExpanded) {
        // Collapse
        childrenContainer.style.display = 'none';
        dirEntry.querySelector('.icon').textContent = '📁';
      } else {
        // Expand
        childrenContainer.style.display = 'block';
        dirEntry.querySelector('.icon').textContent = '📂';
        
        if (!loaded) {
          await loadDir(entry.path, childrenContainer);
          loaded = true;
        }
      }
    });
    
    div.appendChild(dirEntry);
    div.appendChild(childrenContainer);
  } else {
    // File entry
    const fileEntry = document.createElement('div');
    fileEntry.className = 'file-entry file';
    fileEntry.dataset.path = entry.path;
    fileEntry.innerHTML = `<span class="icon">📄</span><span class="name">${escapeHtml(entry.name)}</span>`;
    fileEntry.addEventListener('click', () => {
      // Remove active class from all entries
      document.querySelectorAll('#tab-editor .file-entry').forEach(el => el.classList.remove('active'));
      // Add active class to this entry
      fileEntry.classList.add('active');
      openFile(entry.path);
    });
    div.appendChild(fileEntry);
  }

  containerEl.appendChild(div);
}

// ── EDITOR AI HELPER ──────────────────────────────────────────────────────────
function initEditorAiHelper() {
  const aiSubmitBtn = document.getElementById('editor-ai-submit');
  const aiPromptInput = document.getElementById('editor-ai-prompt');
  
  if (!aiSubmitBtn || !aiPromptInput) return;
  
  aiSubmitBtn.addEventListener('click', async () => {
    const prompt = aiPromptInput.value.trim();
    if (!prompt) {
      showToast('Please enter a prompt', 'warning');
      return;
    }
    
    // Get current file content if available
    const currentFile = state.currentFile;
    const currentContent = getEditorContent();
    
    await sendEditorAiRequest(prompt, currentFile, currentContent);
  });
  
  // Allow Ctrl+Enter to submit
  aiPromptInput.addEventListener('keydown', (e) => {
    if (e.ctrlKey && e.key === 'Enter') {
      aiSubmitBtn.click();
    }
  });
}

async function sendEditorAiRequest(prompt, currentFile, currentContent) {
  const responseContainer = document.getElementById('editor-ai-response');
  if (!responseContainer) return;
  
  responseContainer.classList.add('loading');
  responseContainer.textContent = '';
  
  try {
    // Use the dedicated editor_chat endpoint
    // The server will handle current file context automatically
    const response = await fetch('?action=editor_chat&tab=editor', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Lutin-Token': state.csrfToken,
      },
      body: JSON.stringify({
        message: prompt,
        history: state.editorChatHistory || [],
        current_file: currentFile,
        current_content: currentContent,
      }),
    });

    if (!response.ok) {
      const errorText = await response.text();
      let errorMsg = 'Error sending message';
      try {
        const errorJson = JSON.parse(errorText);
        errorMsg = errorJson.error || errorMsg;
      } catch {}
      responseContainer.classList.remove('loading');
      responseContainer.textContent = '❌ Error: ' + errorMsg;
      return;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let fullResponse = '';
    let openFilePath = null;
    responseContainer.classList.remove('loading');

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      buffer += decoder.decode(value, { stream: true });
      const lines = buffer.split('\n\n');
      buffer = lines.pop();

      for (const line of lines) {
        if (!line.startsWith('data: ')) continue;

        const jsonStr = line.slice(6);
        if (jsonStr === '[DONE]') continue;

        try {
          const event = JSON.parse(jsonStr);
          
          if (event.type === 'text') {
            fullResponse += event.delta;
            responseContainer.textContent = fullResponse;
            responseContainer.scrollTop = responseContainer.scrollHeight;
          } else if (event.type === 'error') {
            responseContainer.textContent = '❌ Error: ' + event.message;
          } else if (event.type === 'tool_start') {
            responseContainer.textContent = fullResponse + '\n[Using tool: ' + event.name + '...]';
          } else if (event.type === 'tool_result') {
            // Check if this is an open_file_in_editor tool result
            const result = JSON.parse(event.result || '{}');
            if (result.ok && result.path) {
              openFilePath = result.path;
            }
          } else if (event.type === 'file_changed') {
            // Refresh the file tree to show changes
            initFileTree();
            // If the changed file is currently open, refresh editor content
            if (state.currentFile === event.path) {
              await openFile(event.path);
              showToast('File refreshed: ' + event.path, 'info');
            }
          }
        } catch (e) {
          console.error('Failed to parse SSE event:', e, line);
        }
      }
    }

    // Open file in editor if requested by the AI
    if (openFilePath) {
      await openFile(openFilePath);
      showToast('Opened: ' + openFilePath, 'info');
    }

    // Add to editor chat history (separate from main chat)
    if (!state.editorChatHistory) {
      state.editorChatHistory = [];
    }
    if (fullResponse) {
      state.editorChatHistory.push({ role: 'user', content: prompt });
      state.editorChatHistory.push({ role: 'assistant', content: fullResponse });
      
      // Keep history manageable (last 20 messages)
      if (state.editorChatHistory.length > 20) {
        state.editorChatHistory = state.editorChatHistory.slice(-20);
      }
    }
    
  } catch (error) {
    responseContainer.classList.remove('loading');
    responseContainer.textContent = '❌ Error: ' + error.message;
  }
}

function updateEditorAiContext(filename) {
  const contextInfo = document.getElementById('ai-context-file');
  if (contextInfo) {
    contextInfo.textContent = filename ? `Context: ${filename}` : 'No file context';
  }
}

// ── FILENAME AUTOCOMPLETE ────────────────────────────────────────────────────
let filenameAutocompleteDebounce = null;
let filenameAutocompleteSelected = -1;

function initFilenameAutocomplete() {
  const filenameInput = document.getElementById('editor-filename');
  const dropdown = document.getElementById('filename-autocomplete');
  
  if (!filenameInput || !dropdown) return;
  
  // Input event for search
  filenameInput.addEventListener('input', () => {
    const query = filenameInput.value.trim();
    
    // Clear previous debounce
    if (filenameAutocompleteDebounce) {
      clearTimeout(filenameAutocompleteDebounce);
    }
    
    if (query.length < 1) {
      hideFilenameAutocomplete();
      return;
    }
    
    // Debounce search
    filenameAutocompleteDebounce = setTimeout(() => {
      searchFiles(query);
    }, 150);
  });
  
  // Keyboard navigation
  filenameInput.addEventListener('keydown', (e) => {
    const items = dropdown.querySelectorAll('.autocomplete-item');
    
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        if (items.length > 0) {
          filenameAutocompleteSelected = Math.min(
            filenameAutocompleteSelected + 1, 
            items.length - 1
          );
          updateAutocompleteSelection(items);
        }
        break;
      case 'ArrowUp':
        e.preventDefault();
        if (items.length > 0) {
          filenameAutocompleteSelected = Math.max(
            filenameAutocompleteSelected - 1, 
            -1
          );
          updateAutocompleteSelection(items);
        }
        break;
      case 'Enter':
        e.preventDefault();
        if (filenameAutocompleteSelected >= 0 && items[filenameAutocompleteSelected]) {
          selectAutocompleteItem(items[filenameAutocompleteSelected]);
        } else {
          openFileFromInput();
        }
        break;
      case 'Escape':
        hideFilenameAutocomplete();
        break;
    }
  });
  
  // Focus out to hide dropdown (with delay to allow clicking items)
  filenameInput.addEventListener('blur', () => {
    setTimeout(() => hideFilenameAutocomplete(), 200);
  });
  
  // Focus to show dropdown if has value
  filenameInput.addEventListener('focus', () => {
    const query = filenameInput.value.trim();
    if (query.length >= 1) {
      searchFiles(query);
    }
  });
}

async function searchFiles(query) {
  const dropdown = document.getElementById('filename-autocomplete');
  if (!dropdown) return;
  
  try {
    const result = await apiGet('search', { 
      q: query,
      strict: 'false',
      files_only: 'true',
      limit: '20'
    });
    
    if (!result.ok) {
      hideFilenameAutocomplete();
      return;
    }
    
    renderAutocompleteResults(result.data);
  } catch (error) {
    hideFilenameAutocomplete();
  }
}

function renderAutocompleteResults(files) {
  const dropdown = document.getElementById('filename-autocomplete');
  if (!dropdown) return;
  
  dropdown.innerHTML = '';
  filenameAutocompleteSelected = -1;
  
  if (files.length === 0) {
    dropdown.innerHTML = '<div class="autocomplete-no-results">No files found</div>';
    dropdown.classList.add('active');
    return;
  }
  
  // Sort by relevance (exact matches first, then by path length)
  const query = document.getElementById('editor-filename')?.value?.toLowerCase() || '';
  files.sort((a, b) => {
    const aPath = a.path.toLowerCase();
    const bPath = b.path.toLowerCase();
    
    // Exact match bonus
    if (aPath === query) return -1;
    if (bPath === query) return 1;
    
    // Starts with bonus
    if (aPath.startsWith(query) && !bPath.startsWith(query)) return -1;
    if (bPath.startsWith(query) && !aPath.startsWith(query)) return 1;
    
    // Shorter paths first
    return aPath.length - bPath.length;
  });
  
  files.forEach((file, index) => {
    const item = document.createElement('div');
    item.className = 'autocomplete-item';
    item.dataset.path = file.path;
    item.dataset.index = index;
    
    const icon = file.type === 'dir' ? '📁' : getFileIcon(file.path);
    
    item.innerHTML = `
      <span class="icon">${icon}</span>
      <span class="name">${escapeHtml(file.name)}</span>
      <span class="path">${escapeHtml(file.path)}</span>
    `;
    
    item.addEventListener('click', () => selectAutocompleteItem(item));
    item.addEventListener('mouseenter', () => {
      filenameAutocompleteSelected = index;
      updateAutocompleteSelection(dropdown.querySelectorAll('.autocomplete-item'));
    });
    
    dropdown.appendChild(item);
  });
  
  dropdown.classList.add('active');
}

function getFileIcon(path) {
  if (path.endsWith('.php')) return '🐘';
  if (path.endsWith('.js')) return '📜';
  if (path.endsWith('.css')) return '🎨';
  if (path.endsWith('.html') || path.endsWith('.htm')) return '🌐';
  if (path.endsWith('.json')) return '📋';
  if (path.endsWith('.md')) return '📝';
  if (path.endsWith('.sql')) return '🗄️';
  return '📄';
}

function updateAutocompleteSelection(items) {
  items.forEach((item, index) => {
    if (index === filenameAutocompleteSelected) {
      item.classList.add('selected');
      item.scrollIntoView({ block: 'nearest' });
    } else {
      item.classList.remove('selected');
    }
  });
}

function selectAutocompleteItem(item) {
  const path = item.dataset.path;
  const filenameInput = document.getElementById('editor-filename');
  if (filenameInput) {
    filenameInput.value = path;
  }
  hideFilenameAutocomplete();
  openFile(path);
}

function hideFilenameAutocomplete() {
  const dropdown = document.getElementById('filename-autocomplete');
  if (dropdown) {
    dropdown.classList.remove('active');
    dropdown.innerHTML = '';
  }
  filenameAutocompleteSelected = -1;
}

// ── CONFIG ────────────────────────────────────────────────────────────────────
function initConfig() {
  const configForm = document.getElementById('config-form');
  if (configForm) {
    configForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      await saveConfig();
    });
    loadConfig();
  }

  loadBackups();
}

function loadConfig() {
  if (!window.LUTIN_CONFIG) return;

  document.getElementById('config-provider').value = window.LUTIN_CONFIG.provider || 'anthropic';
  document.getElementById('config-model').value = window.LUTIN_CONFIG.model || '';
  document.getElementById('config-site-url').value = window.LUTIN_CONFIG.siteUrl || '';
}

async function saveConfig() {
  const apiKeyInput = document.getElementById('config-api-key');
  const apiKeyValue = apiKeyInput.value;

  const formData = {
    provider: document.getElementById('config-provider').value,
    api_key: apiKeyValue,
    model: document.getElementById('config-model').value,
    site_url: document.getElementById('config-site-url').value,
  };

  try {
    const result = await apiPost('config', formData);
    if (result.ok) {
      // Keep the API key visible in the field (it was just entered by user)
      // This provides better UX - user sees their input was saved
      showToast('Config saved!', 'success');
      // Update the config in memory so subsequent saves use current values
      window.LUTIN_CONFIG = window.LUTIN_CONFIG || {};
      window.LUTIN_CONFIG.provider = formData.provider;
      window.LUTIN_CONFIG.model = formData.model;
      window.LUTIN_CONFIG.siteUrl = formData.site_url;
    } else {
      showToast('Error: ' + result.error, 'error');
    }
  } catch (error) {
    showToast('Error: ' + error.message, 'error');
  }
}

async function loadBackups(showError = true) {
  try {
    const result = await apiGet('backups');
    if (!result.ok) {
      // Silently ignore 401 (unauthorized) during init - user may not be authenticated yet
      if (result.httpStatus === 401) {
        return;
      }
      if (showError) {
        showToast('Error loading backups: ' + result.error, 'error');
      }
      return;
    }

    const backupList = document.getElementById('backup-list');
    if (!backupList) return;

    backupList.innerHTML = '';

    // Show message if no backups
    if (!result.data || result.data.length === 0) {
      backupList.innerHTML = '<p style="color: #999;">No backups yet</p>';
      return;
    }

    for (const backup of result.data) {
      const div = document.createElement('div');
      div.className = 'backup-entry';
      div.style.cssText = `
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem;
        border: 1px solid #ddd;
        margin-bottom: 0.5rem;
        border-radius: 4px;
      `;
      div.innerHTML = `
        <div>
          <strong>${escapeHtml(backup.original_name)}</strong><br>
          <small>${escapeHtml(backup.timestamp)} (${backup.size} bytes)</small>
        </div>
        <div>
          <button data-backup-path="${escapeHtml(backup.backup_path)}" class="btn-view">View</button>
          <button data-backup-path="${escapeHtml(backup.backup_path)}" class="btn-restore">Restore</button>
        </div>
      `;
      backupList.appendChild(div);
    }

    // Add event listeners
    document.querySelectorAll('.btn-view').forEach(btn => {
      btn.addEventListener('click', async () => {
        await viewBackup(btn.dataset.backupPath);
      });
    });

    document.querySelectorAll('.btn-restore').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (confirm('Restore this backup?')) {
          await restoreBackup(btn.dataset.backupPath);
        }
      });
    });
  } catch (error) {
    if (showError) {
      showToast('Error: ' + error.message, 'error');
    }
  }
}

async function viewBackup(backupPath) {
  try {
    const result = await apiGet('read', { path: backupPath });
    if (result.ok) {
      setEditorContent(result.data.content, backupPath);
      document.getElementById('editor-filename').textContent = backupPath + ' (backup)';
    } else {
      showToast('Error: ' + result.error, 'error');
    }
  } catch (error) {
    showToast('Error: ' + error.message, 'error');
  }
}

async function restoreBackup(backupPath) {
  try {
    const result = await apiPost('restore', { path: backupPath });
    if (result.ok) {
      showToast('Backup restored!', 'success');
      await loadBackups();
    } else {
      showToast('Error: ' + result.error, 'error');
    }
  } catch (error) {
    showToast('Error: ' + error.message, 'error');
  }
}

// ── URL LOOKUP ────────────────────────────────────────────────────────────────
function initUrlLookup() {
  const urlForm = document.getElementById('url-lookup-form');
  if (!urlForm) return;

  urlForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const url = document.getElementById('url-input').value;
    if (!url) return;

    try {
      const result = await apiGet('url_map', { url });
      if (result.ok && result.data.length > 0) {
        if (result.data.length === 1) {
          openFile(result.data[0]);
        } else {
          // Show picker
          const choice = prompt('Multiple matches found:\n' + result.data.join('\n') + '\n\nEnter number (0-' + (result.data.length - 1) + '):');
          if (choice !== null && choice in result.data) {
            openFile(result.data[choice]);
          }
        }
      } else {
        showToast('No matching file found', 'warning');
      }
    } catch (error) {
      showToast('Error: ' + error.message, 'error');
    }
  });
}

// ── SETUP WIZARD ──────────────────────────────────────────────────────────────
function initSetup() {
  const setupForm = document.getElementById('setup-form');
  if (!setupForm) return;

  setupForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    const password = document.getElementById('setup-password').value;
    const confirm = document.getElementById('setup-confirm').value;

    if (password !== confirm) {
      showToast('Passwords do not match', 'error');
      return;
    }

    try {
      const result = await apiPost('setup', {
        password,
        confirm,
        provider: document.getElementById('setup-provider').value,
        api_key: document.getElementById('setup-api-key').value,
        model: document.getElementById('setup-model').value,
        site_url: document.getElementById('setup-site-url').value,
      });

      if (result.ok) {
        showToast('Setup complete! Redirecting...', 'success');
        setTimeout(() => location.reload(), 1000);
      } else {
        showToast('Setup error: ' + result.error, 'error');
      }
    } catch (error) {
      showToast('Error: ' + error.message, 'error');
    }
  });
}

// ── LOGIN ──────────────────────────────────────────────────────────────────────
function initLogin() {
  const loginForm = document.getElementById('login-form');
  if (!loginForm) return;

  loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    try {
      const result = await apiPost('login', {
        password: document.getElementById('login-password').value,
      });

      if (result.ok) {
        showToast('Logged in! Redirecting...', 'success');
        setTimeout(() => location.reload(), 1000);
      } else {
        showToast('Login failed: ' + result.error, 'error');
      }
    } catch (error) {
      showToast('Error: ' + error.message, 'error');
    }
  });
}

// ── TEMPLATE SELECTION ───────────────────────────────────────────────────────
function initTemplates() {
  const templatesGrid = document.getElementById('templates-grid');
  if (!templatesGrid) return;

  // Load available templates
  loadTemplates();

  // Add click handlers for template selection
  templatesGrid.addEventListener('click', (e) => {
    const btn = e.target.closest('.select-template-btn');
    if (!btn) return;

    const templateId = btn.dataset.templateId;
    const templateCard = btn.closest('.template-card');
    const zipUrl = templateCard?.dataset.zipUrl;
    const hash = templateCard?.dataset.hash;

    installTemplate(templateId, zipUrl, hash);
  });
}

async function loadTemplates() {
  const loadingEl = document.getElementById('templates-loading');
  const errorEl = document.getElementById('templates-error');
  const gridEl = document.getElementById('templates-grid');

  try {
    const result = await apiGet('templates');
    
    if (result.ok && result.data.templates) {
      // Add template cards
      for (const template of result.data.templates) {
        addTemplateCard(template);
      }
    }

    // Show the grid (even if empty, since we have "Empty Project" option)
    loadingEl.style.display = 'none';
    gridEl.style.display = 'grid';

    if (result.data.error) {
      console.warn('Template loading issue:', result.data.error);
    }
  } catch (error) {
    console.error('Failed to load templates:', error);
    loadingEl.style.display = 'none';
    errorEl.style.display = 'block';
    gridEl.style.display = 'grid';
  }
}

function addTemplateCard(template) {
  const gridEl = document.getElementById('templates-grid');
  if (!gridEl) return;

  const article = document.createElement('article');
  article.className = 'template-card';
  article.dataset.templateId = template.id;
  article.dataset.zipUrl = template.download_url || '';
  article.dataset.hash = template.hash || '';
  article.style.cssText = 'cursor: pointer; border: 2px solid transparent;';

  const name = escapeHtml(template.name || template.id);
  const description = escapeHtml(template.description || 'A template for your project.');

  article.innerHTML = `
    <h3>${name}</h3>
    <p>${description}</p>
    <button type="button" class="select-template-btn" data-template-id="${escapeHtml(template.id)}">Select Template</button>
  `;

  // Insert before the last child (the Empty Project option should stay first)
  const emptyCard = gridEl.querySelector('[data-template-id=""]');
  if (emptyCard && emptyCard.nextElementSibling) {
    gridEl.insertBefore(article, emptyCard.nextElementSibling);
  } else {
    gridEl.appendChild(article);
  }
}

async function installTemplate(templateId, zipUrl, hash) {
  const installingEl = document.getElementById('template-installing');
  const gridEl = document.getElementById('templates-grid');

  // Show installing state
  if (installingEl) installingEl.style.display = 'block';
  if (gridEl) gridEl.style.opacity = '0.5';

  try {
    const result = await apiPost('install_template', {
      template_id: templateId,
      zip_url: zipUrl,
      hash: hash,
    });

    if (result.ok) {
      showToast(templateId ? 'Template installed successfully!' : 'Starting with empty project', 'success');
      setTimeout(() => location.reload(), 1500);
    } else {
      const errorMsg = result.error || 'Unknown error';
      console.error('[Lutin] Template installation failed:', errorMsg);
      console.error('[Lutin] Template ID:', templateId, 'ZIP URL:', zipUrl);
      showToast('Installation failed: ' + errorMsg, 'error');
      if (installingEl) installingEl.style.display = 'none';
      if (gridEl) gridEl.style.opacity = '1';
    }
  } catch (error) {
    console.error('[Lutin] Template installation error:', error);
    showToast('Installation error: ' + error.message, 'error');
    if (installingEl) installingEl.style.display = 'none';
    if (gridEl) gridEl.style.opacity = '1';
  }
}

// ── INIT ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  initSetup();
  initLogin();
  initTabs();
  initChat();
  initEditor();
  initFileTree();
  initFilenameAutocomplete();
  initEditorAiHelper();
  initConfig();
  initUrlLookup();
  initTemplates();
});

// Make showTab globally accessible for inline onclick handlers
window.showTab = function(tabName) {
  // Hide all sections
  document.querySelectorAll('section').forEach(s => s.style.display = 'none');

  // Show selected section
  const section = document.getElementById(`tab-${tabName}`);
  if (section) section.style.display = 'block';

  // Update nav styling if nav exists
  const navLinks = document.querySelectorAll('nav a');
  if (navLinks.length > 0) {
    navLinks.forEach(a => {
      a.removeAttribute('aria-current');
      if (a.href.includes(`#${tabName}`)) {
        a.setAttribute('aria-current', 'page');
      }
    });
  }
  
  // Update hash
  location.hash = tabName;
};
