(() => {
  "use strict";
  document.querySelectorAll("[data-mcp-chat]").forEach(root => {
    if (root.dataset.bound) return;
    root.dataset.bound = "true";
    const form = root.querySelector("[data-chat-form]");
    const input = form.querySelector("textarea");
    const button = form.querySelector("button");
    const messages = root.querySelector("[data-messages]");
    const status = root.querySelector("[data-status]");
    let history = [];
    const append = (text, role) => {
      const item = document.createElement("div");
      item.className = "mcp-bubble" + (role === "user" ? " mcp-bubble-user" : "");
      item.textContent = text;
      messages.appendChild(item);
      messages.scrollTop = messages.scrollHeight;
    };
    form.addEventListener("submit", async event => {
      event.preventDefault();
      const message = input.value.trim();
      if (!message || button.disabled) return;
      const endpoint = new URL(root.dataset.endpoint, window.location.href);
      if (endpoint.origin !== window.location.origin) return;
      const body = { message, history: history.slice(-10), locale: (root.dataset.locale || "en").slice(0, 2) };
      const encoder = new TextEncoder();
      while (body.history.length && encoder.encode(JSON.stringify(body)).length > 28000) body.history.shift();
      if (encoder.encode(JSON.stringify(body)).length > 30000) { status.textContent = root.dataset.error; return; }
      append(message, "user");
      button.disabled = true;
      status.textContent = root.dataset.wait;
      try {
        const response = await fetch(endpoint.href, { method: "POST", credentials: "same-origin",
          headers: { "Content-Type": "application/json", "Accept": "application/json",
            "X-CSRF-TOKEN": form.querySelector('input[name="_token"]').value },
          body: JSON.stringify(body) });
        const result = await response.json();
        if (!response.ok || result.success !== true || typeof result.data?.text !== "string") throw new Error("request rejected");
        append(result.data.text, "assistant");
        history = [...body.history, { role: "user", text: message }, { role: "assistant", text: result.data.text.slice(0, 8000) }].slice(-10);
        for (const proposal of result.data.proposals || []) {
          for (const receipt of proposal.data || []) {
            if (receipt.status !== "pending" || receipt.requires_confirmation !== true
                || typeof receipt.proposal_ref !== "string" || !/^prop_[a-p]{48}$/.test(receipt.proposal_ref)) continue;
            const url = new URL(root.dataset.approval.replace("__proposal__", encodeURIComponent(receipt.proposal_ref)), window.location.href);
            if (url.origin !== window.location.origin) continue;
            const link = document.createElement("a");
            link.href = url.href;
            link.textContent = root.dataset.review;
            link.target = "_blank";
            link.rel = "noopener noreferrer";
            messages.appendChild(link);
          }
        }
        const exported = new Set();
        for (const report of Array.isArray(result.data.exports) ? result.data.exports.slice(0, 20) : []) {
          for (const receipt of Array.isArray(report?.data) ? report.data.slice(0, 20) : []) {
            if (!receipt || typeof root.dataset.export !== "string") continue;
            if (receipt.status !== "ready" || !["csv", "xlsx", "pdf"].includes(receipt.format)
                || typeof receipt.export_ref !== "string" || !/^exp_[a-p]{48}$/.test(receipt.export_ref)
                || exported.has(receipt.export_ref)) continue;
            const url = new URL(root.dataset.export.replace("__export__", receipt.export_ref), window.location.href);
            if (url.origin !== window.location.origin || url.search || url.hash) continue;
            const link = document.createElement("a");
            link.href = url.href;
            link.textContent = root.dataset.download + " (" + receipt.format.toUpperCase() + ")";
            link.target = "_blank";
            link.rel = "noopener noreferrer";
            messages.appendChild(link);
            exported.add(receipt.export_ref);
          }
        }
        input.value = "";
        status.textContent = "";
      } catch {
        status.textContent = root.dataset.error;
      } finally {
        button.disabled = false;
        input.focus();
      }
    });
  });
})();
