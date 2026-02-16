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
        Data Directory (optional)
        <input type="text" id="setup-data-dir" name="data_dir" placeholder="../lutin">
        <small>Where Lutin stores config and backups. Default is outside the web root for security.</small>
      </label>
      <button type="submit">Set up Lutin</button>
    </form>
  </article>
</section>
