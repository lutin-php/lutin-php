<section id="tab-editor">
  <div style="display: grid; grid-template-columns: 250px 1fr; gap: 1rem; height: 600px;">
    <aside id="file-tree">
      <form id="url-lookup-form">
        <fieldset>
          <input id="url-input" type="url" placeholder="Paste page URL…">
          <button type="submit">Find</button>
        </fieldset>
      </form>
      <div id="file-list"></div>
    </aside>
    <div id="editor-panel">
      <div id="editor-toolbar" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid #ccc;">
        <span id="editor-filename" style="font-weight: bold;">No file open</span>
        <button id="save-btn" disabled>Save</button>
      </div>
      <div id="codemirror-container" style="border: 1px solid #ccc; border-radius: 4px;"></div>
    </div>
  </div>
</section>
