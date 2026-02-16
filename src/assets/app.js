// ── STATE ─────────────────────────────────────────────────────────────────────
const state = {
  csrfToken: document.querySelector('meta[name="lutin-token"]')?.content ?? '',
  currentFile: null,        // relative path of open file
  cmEditor: null,           // CodeMirror instance
  chatHistory: [],          // [{role, content}] accumulated for context
  isStreaming: false,       // true while SSE is open
};

// ── UTILS ─────────────────────────────────────────────────────────────────────
async function apiPost(action, body) {
  const response = await fetch(`?action=${action}`, {
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
  const query = new URLSearchParams({ action, ...params }).toString();
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
  function showTab(tabName) {
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
  }

  // Handle hash changes - only show a different tab if hash is explicitly set
  window.addEventListener('hashchange', () => {
    if (location.hash) {
      const hash = location.hash.slice(1);
      showTab(hash);
    }
  });

  // Initial show - only if hash is explicitly set in URL
  // Otherwise, trust the CSS to show the correct initial tab (from PHP)
  if (location.hash) {
    const initialTab = location.hash.slice(1);
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
    const response = await fetch('?action=chat', {
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
  if (!cmContainer) return;

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

  const saveBtn = document.getElementById('save-btn');
  if (saveBtn) {
    saveBtn.addEventListener('click', saveFile);
  }
}

async function openFile(path) {
  try {
    const result = await apiGet('read', { path });
    if (!result.ok) {
      showToast('Error reading file: ' + result.error, 'error');
      return;
    }

    state.currentFile = path;
    const mode = detectMode(path);
    state.cmEditor.setOption('mode', mode);
    state.cmEditor.setValue(result.data.content);

    document.getElementById('editor-filename').textContent = path;
    document.getElementById('save-btn').disabled = false;
  } catch (error) {
    showToast('Error: ' + error.message, 'error');
  }
}

async function saveFile() {
  if (!state.currentFile) {
    showToast('No file selected', 'warning');
    return;
  }

  try {
    const result = await apiPost('write', {
      path: state.currentFile,
      content: state.cmEditor.getValue(),
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
  return 'null';
}

// ── FILE TREE ─────────────────────────────────────────────────────────────────
function initFileTree() {
  const fileList = document.getElementById('file-list');
  if (!fileList) return;

  loadDir('', fileList);
}

async function loadDir(path, containerEl) {
  try {
    const result = await apiGet('list', { path });
    if (!result.ok) {
      showToast('Error: ' + result.error, 'error');
      return;
    }

    containerEl.innerHTML = '';
    for (const entry of result.data) {
      renderFileEntry(entry, containerEl);
    }
  } catch (error) {
    showToast('Error: ' + error.message, 'error');
  }
}

function renderFileEntry(entry, containerEl) {
  const div = document.createElement('div');
  div.style.paddingLeft = '1rem';

  if (entry.type === 'dir') {
    const details = document.createElement('details');
    const summary = document.createElement('summary');
    summary.textContent = '📁 ' + entry.name;
    summary.style.cursor = 'pointer';
    const subDir = document.createElement('div');

    details.appendChild(summary);
    details.appendChild(subDir);

    details.addEventListener('toggle', async () => {
      if (details.open && subDir.children.length === 0) {
        await loadDir(entry.path, subDir);
      }
    });

    div.appendChild(details);
  } else {
    const link = document.createElement('a');
    link.href = '#';
    link.textContent = '📄 ' + entry.name;
    link.style.display = 'block';
    link.style.cursor = 'pointer';
    link.onclick = (e) => {
      e.preventDefault();
      openFile(entry.path);
    };
    div.appendChild(link);
  }

  containerEl.appendChild(div);
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
      state.cmEditor.setValue(result.data.content);
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
  const description = escapeHtml(template.description || 'A starter template for your project.');

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
