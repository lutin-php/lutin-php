<section id="tab-config">
  <div style="max-width: 600px; margin: 0 auto;">
    <h2>Configuration</h2>
    <form id="config-form">
      <label>
        AI Provider
        <select id="config-provider" name="provider">
          <option value="anthropic">Anthropic (Claude)</option>
          <option value="openai">OpenAI (GPT)</option>
        </select>
      </label>
      <label>
        API Key
        <input type="password" id="config-api-key" name="api_key">
      </label>
      <label>
        Model
        <input type="text" id="config-model" name="model">
      </label>
      <label>
        Site URL (optional)
        <input type="url" id="config-site-url" name="site_url">
      </label>
      <button type="submit">Save Config</button>
    </form>

    <hr>

    <h3>Backups</h3>
    <div id="backup-list"></div>
  </div>
</section>
