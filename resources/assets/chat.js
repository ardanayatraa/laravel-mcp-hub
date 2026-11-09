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
    // A small Markdown subset (tables, lists, headings, **bold**, `code`) built with DOM nodes only:
    // model text never becomes HTML, and links in it stay plain text.
    const inline = (parent, text) => {
      const pattern = /\*\*([^*\n]+)\*\*|`([^`\n]+)`/g;
      let last = 0;
      let match;
      while ((match = pattern.exec(text))) {
        if (match.index > last) parent.appendChild(document.createTextNode(text.slice(last, match.index)));
        const node = document.createElement(match[1] !== undefined ? "strong" : "code");
        node.textContent = match[1] !== undefined ? match[1] : match[2];
        parent.appendChild(node);
        last = pattern.lastIndex;
      }
      if (last < text.length) parent.appendChild(document.createTextNode(text.slice(last)));
    };
    const cells = line => line.trim().replace(/^\|/, "").replace(/\|$/, "").split("|").map(cell => cell.trim());
    const divider = /^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/;
    const render = (box, text) => {
      const lines = text.replace(/\r\n?/g, "\n").split("\n");
      let paragraph = [];
      let list = null;
      let listTag = "";
      const flush = () => {
        if (paragraph.length) {
          const p = document.createElement("p");
          inline(p, paragraph.join("\n"));
          box.appendChild(p);
        }
        paragraph = [];
      };
      for (let index = 0; index < lines.length; index++) {
        const line = lines[index].trim();
        const item = /^(?:[-*•]|(\d{1,3})[.)])\s+(.*)$/.exec(line);
        if (!item) list = null;
        if (!line) { flush(); continue; }
        if (line.startsWith("|") && divider.test(lines[index + 1] || "")) {
          flush();
          const wrap = document.createElement("div");
          wrap.className = "mcp-table";
          const table = document.createElement("table");
          const row = (values, tag) => {
            const tr = document.createElement("tr");
            for (const value of values) {
              const cell = document.createElement(tag);
              inline(cell, value);
              tr.appendChild(cell);
            }
            table.appendChild(tr);
          };
          row(cells(line), "th");
          for (index += 2; index < lines.length && lines[index].trim().startsWith("|"); index++) row(cells(lines[index]), "td");
          index--;
          wrap.appendChild(table);
          box.appendChild(wrap);
          continue;
        }
        const heading = /^#{1,4}\s+(.+)$/.exec(line);
        if (heading) {
          flush();
          const p = document.createElement("p");
          const strong = document.createElement("strong");
          inline(strong, heading[1]);
          p.appendChild(strong);
          box.appendChild(p);
          continue;
        }
        if (item) {
          flush();
          const tag = item[1] === undefined ? "ul" : "ol";
          if (!list || listTag !== tag) {
            list = document.createElement(tag);
            listTag = tag;
            box.appendChild(list);
          }
          const li = document.createElement("li");
          inline(li, item[2]);
          list.appendChild(li);
          continue;
        }
        paragraph.push(line);
      }
      flush();
    };
    const append = (text, role) => {
      const item = document.createElement("div");
      item.className = "mcp-bubble" + (role === "user" ? " mcp-bubble-user" : " mcp-rich");
      if (role === "user") item.textContent = text;
      else render(item, text);
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
