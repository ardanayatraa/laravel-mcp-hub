(() => {
  "use strict";
  const root = document.querySelector("[data-mcp-settings]");
  if (!root) return;
  const status = root.querySelector("[data-settings-status]");
  let busy = false;
  async function request(action, body = {}) {
    if (busy) return null;
    const endpoint = new URL(root.dataset[action], window.location.href);
    if (endpoint.origin !== window.location.origin) return null;
    busy = true;
    root.querySelectorAll("button").forEach(button => button.disabled = true);
    status.textContent = root.dataset.wait;
    try {
      const response = await fetch(endpoint.href, { method: "POST", credentials: "same-origin",
        headers: { "Accept": "application/json", "Content-Type": "application/json",
          "X-CSRF-TOKEN": root.querySelector("[data-csrf]").value }, body: JSON.stringify(body) });
      const result = await response.json();
      if (!response.ok || result.success !== true) throw new Error("request rejected");
      status.textContent = root.dataset.saved;
      return result.data;
    } catch {
      status.textContent = root.dataset.error;
      return null;
    } finally {
      busy = false;
      root.querySelectorAll("button").forEach(button => button.disabled = false);
    }
  }
  const form = root.querySelector("[data-settings-form]");
  form?.addEventListener("submit", async event => {
    event.preventDefault();
    const resources = {};
    root.querySelectorAll("[data-resource]").forEach(fieldset => {
      resources[fieldset.dataset.resource] = {
        enabled: fieldset.querySelector("[data-resource-enabled]").checked,
        fields: [...fieldset.querySelectorAll("[data-field]:checked")].map(input => input.dataset.field),
        operations: [...fieldset.querySelectorAll("[data-operation]:checked")].map(input => input.dataset.operation),
      };
    });
    const result = await request("save", { revision: root.dataset.revision,
      enabled: root.querySelector("[data-enabled]").checked, resources });
    if (result?.revision) root.dataset.revision = result.revision;
  });
  root.querySelector("[data-connect-form]")?.addEventListener("submit", async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const result = await request("connect", { hub: form.elements.hub.value, code: form.elements.code.value });
    form.elements.code.value = "";
    if (result) window.location.reload();
  });
  root.querySelectorAll("[data-action]").forEach(button => button.addEventListener("click", async () => {
    const result = await request(button.dataset.action);
    if (result && button.dataset.action === "check") {
      status.textContent = result.ok ? root.dataset.checkOk : root.dataset.checkFailed;
    } else if (result) {
      window.location.reload();
    }
  }));
})();
