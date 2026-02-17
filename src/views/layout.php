<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="lutin-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <title>Lutin — <?= htmlspecialchars($siteTitle) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jodit@4.2.47/es2021/jodit.min.css">
  <script src="https://cdn.jsdelivr.net/npm/jodit@4.2.47/es2021/jodit.min.js"></script>
  <style>
    /* Hide all sections by default, JavaScript will show the active one */
    section { display: none; }
    #tab-<?= htmlspecialchars($activeTab) ?> { display: block; } /* Show active tab initially */
    
    /* Loading indicator animation */
    .message--loading .loading-dots span {
      animation: loadingDots 1.4s infinite ease-in-out both;
      display: inline-block;
    }
    .message--loading .loading-dots span:nth-child(1) { animation-delay: -0.32s; }
    .message--loading .loading-dots span:nth-child(2) { animation-delay: -0.16s; }
    .message--loading .loading-dots span:nth-child(3) { animation-delay: 0s; }
    
    @keyframes loadingDots {
      0%, 80%, 100% { opacity: 0; }
      40% { opacity: 1; }
    }
    
    .message--error .message__content {
      color: #dc3545;
      background: rgba(220, 53, 69, 0.1);
      padding: 0.75rem;
      border-radius: 4px;
    }
    
    /* Chat message styling improvements */
    .message {
      margin-bottom: 1rem;
    }
    .message__content {
      padding: 0.75rem 1rem;
      border-radius: 4px;
    }
    .message--user .message__content {
      background: var(--primary);
      color: white;
      margin-left: 2rem;
    }
    .message--assistant .message__content {
      background: var(--card-background-color);
      margin-right: 2rem;
    }
  </style>
  <script>window.LUTIN_CONFIG = <?= json_encode($jsConfig) ?>;</script>
</head>
<body>
  <?php if (in_array($activeTab, ['chat', 'editor', 'config'])): ?>
  <nav>
    <ul>
      <li><a href="#chat">Chat</a></li>
      <li><a href="#editor">Editor</a></li>
      <li><a href="#config">Config</a></li>
    </ul>
  </nav>
  <?php endif; ?>
  <main><?= $tabContent ?></main>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/clike/clike.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/php/php.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/sql/sql.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/markdown/markdown.js"></script>
  <script><?= $appJs ?></script>
</body>
</html>
