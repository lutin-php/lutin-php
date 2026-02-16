<section id="tab-templates">
  <article style="max-width: 800px; margin: 2rem auto;">
    <h2>Choose a Starter Template</h2>
    <p>Select a template to get started quickly, or choose "Empty Project" to start from scratch.</p>
    
    <div id="templates-loading" class="loading-indicator">
      <p>Loading available templates...</p>
    </div>
    
    <div id="templates-error" style="display: none;" class="message message--error">
      <p>Failed to load templates. You can still start with an empty project.</p>
    </div>
    
    <div id="templates-grid" class="grid" style="display: none;">
      <!-- Empty project option -->
      <article class="template-card" data-template-id="" style="cursor: pointer; border: 2px solid transparent;">
        <h3>🚀 Empty Project</h3>
        <p>Start from scratch with a clean slate.</p>
        <button type="button" class="select-template-btn" data-template-id="">Start Empty</button>
      </article>
      
      <!-- Template cards will be inserted here -->
    </div>
    
    <div id="template-installing" style="display: none; margin-top: 2rem;">
      <p>Installing template... <span class="loading-dots">Please wait</span></p>
      <progress id="install-progress" style="width: 100%;"></progress>
    </div>
  </article>
</section>

<style>
.template-card {
  padding: 1.5rem;
  border-radius: 8px;
  background: var(--card-background-color);
  transition: border-color 0.2s, transform 0.2s;
}
.template-card:hover {
  border-color: var(--primary);
  transform: translateY(-2px);
}
.template-card.selected {
  border-color: var(--primary);
}
.template-card h3 {
  margin-top: 0;
  margin-bottom: 0.5rem;
}
.template-card p {
  margin-bottom: 1rem;
  color: var(--muted-color);
  font-size: 0.9rem;
}
.select-template-btn {
  width: 100%;
}
</style>
