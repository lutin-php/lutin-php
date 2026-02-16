<section id="tab-setup">
  <article style="max-width: 400px; margin: 3rem auto;">
    <h2>Set up Lutin</h2>
    <form id="setup-form">
      <label>
        Password
        <input type="password" id="setup-password" name="password" required>
      </label>
      <label>
        Confirm Password
        <input type="password" id="setup-confirm" name="confirm" required>
      </label>
      <label>
        AI Provider
        <select id="setup-provider" name="provider">
          <option value="anthropic">Anthropic (Claude)</option>
          <option value="openai">OpenAI (GPT)</option>
        </select>
      </label>
      <label>
        API Key
        <input type="password" id="setup-api-key" name="api_key" required>
      </label>
      <label>
        Model
        <input type="text" id="setup-model" name="model" placeholder="claude-3-5-haiku-20241022">
      </label>
      <label>
        Site URL (optional)
        <input type="url" id="setup-site-url" name="site_url" placeholder="https://example.com">
      </label>
      <label>
        Project Root Directory (optional)
        <input type="text" id="setup-project-root" name="project_root" placeholder="/path/to/project">
        <small>The root directory of your project. The AI can access all files here except the lutin/ directory. Default is the parent directory of where lutin.php is located.</small>
      </label>
      <label>
        Web Root Directory (optional)
        <input type="text" id="setup-web-root" name="web_root" placeholder="/path/to/public">
        <small>The directory where lutin.php lives and public-facing files are served from. This is typically a subdirectory of Project Root (e.g., public/, www/, html/). Default is the directory where lutin.php is located.</small>
      </label>
      <button type="submit">Set up Lutin</button>
    </form>
  </article>
</section>
