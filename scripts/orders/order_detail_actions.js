console.log("ORDER DETAIL ACTIONS LOADED v-profile-1");
$(document)
  .off("click.takeOrder", ".btn-take-order")
  .on("click.takeOrder", ".btn-take-order", function (e) {
    e.preventDefault();
    e.stopPropagation();

    const $btn = $(this);
    const orderId = $btn.data("order-id");
    const deptCode = $btn.data("dept-code") || "";

    if (!orderId) {
      alert("Missing order ID");
      return;
    }

    $btn.prop("disabled", true).text("...");

    $.ajax({
      url: "scripts/orders/take_order.php",
      method: "POST",
      dataType: "json",
      data: {
        order_id: orderId,
        dept_code: deptCode,
      },
      success: function (resp) {
        if (!resp || !resp.ok) {
          alert("TAKE error: " + (resp && resp.error ? resp.error : "unknown"));
          $btn.prop("disabled", false).text("TAKE");
          return;
        }

        // V profile contexte refreshujeme zoznam + znova otvoríme detail
        if (typeof window.refreshProfileOrdersList === "function") {
          window.refreshProfileOrdersList(orderId);
          return;
        }

        location.reload();
      },
      error: function (xhr) {
        console.log(xhr.responseText);
        alert("TAKE error request failed");
        $btn.prop("disabled", false).text("TAKE");
      },
    });
  });
(function () {
  let currentOptionsItemId = 0;
  let currentInternalOptions = {};
  let currentCustomerOptions = {};
  let currentCanEditOptions = false;

  function copyTextFallback(text) {
    const textarea = document.createElement("textarea");
    textarea.value = text;
    textarea.style.position = "fixed";
    textarea.style.left = "-9999px";
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();

    let copied = false;
    try {
      copied = document.execCommand("copy");
    } catch (e) {
      copied = false;
    }

    document.body.removeChild(textarea);
    return copied;
  }

  function escapeHtml(str) {
    return String(str ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function getOptionsData($btn) {
    const raw = $btn.attr("data-options") || "{}";

    try {
      return JSON.parse(raw);
    } catch (e) {
      return {};
    }
  }

  window.getOptionsData = getOptionsData;

  function getRawOptionsData($btn) {
    const raw =
      $btn.attr("data-options-raw") || $btn.attr("data-options") || "{}";

    try {
      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === "object" && !Array.isArray(parsed)
        ? parsed
        : {};
    } catch (e) {
      return {};
    }
  }

  function stripProtectedOptions(data) {
    const copy = { ...(data || {}) };

    if (!window.isSuperAdmin) {
      delete copy._item;
      delete copy._source_raw;
    }

    return copy;
  }

  function renderOptionsPretty(data) {
    if (!data || Object.keys(data).length === 0) {
      return '<div class="text-muted">No options</div>';
    }

    const isPatchDetail = Object.prototype.hasOwnProperty.call(
      data,
      "patch-style",
    );

    function section(title, obj) {
      if (!obj || Object.keys(obj).length === 0) return "";

      let rows = "";

      for (let k in obj) {
        rows += `
          <div class="mb-1">
            <span class="text-muted">${escapeHtml(k)}:</span>
            <b style="white-space:pre-wrap;">${escapeHtml(obj[k])}</b>
          </div>
        `;
      }

      return `
        <div class="card bg-secondary mb-3">
          <div class="card-header py-2">
            <b>${escapeHtml(title)}</b>
          </div>
          <div class="card-body py-2">
            ${rows}
          </div>
        </div>
      `;
    }

    const bike = {};
    const personal = {};
    const graphics = {};
    const files = {};
    const other = {};

    for (let k in data) {
      // technické raw import dáta iba pre superadmina
      if (!window.isSuperAdmin && (k === "_item" || k === "_source_raw")) {
        continue;
      }
      let v = data[k];

      if (v === null || v === "") continue;
      if (String(k).startsWith("_")) continue;
      if (typeof v === "object") continue;

      let label = k;

      if (k === "name-color") label = "number plates color";
      if (k === "applyinggraphics") label = "Fitting";

      if (k === "number-font" || k === "name-font") {
        const match = String(v).match(/(\d+)$/);
        if (match) v = match[1];
      }

      const key = k.toLowerCase();

      if (
        k === "Category Info" ||
        key.includes("category") ||
        key.includes("bike") ||
        key.includes("manufacturer")
      ) {
        bike[label] = v;
      } else if (
        key.includes("name") ||
        key.includes("number") ||
        key.includes("font")
      ) {
        personal[label] = v;
      } else if (
        key.includes("material") ||
        key.includes("finish") ||
        key.includes("graphics") ||
        key.includes("rim") ||
        key.includes("fork")
      ) {
        graphics[label] = v;
      } else if (
        key.includes("file") ||
        key.includes("logo") ||
        key.includes("upload")
      ) {
        files[label] = v;
      } else {
        other[label] = v;
      }
    }

    let warnings = [];

    if (!isPatchDetail && !data.name && !data.Name)
      warnings.push("Missing rider name");
    if (!isPatchDetail && !data.file && !data.logo && !data.uploaded_file)
      warnings.push("Missing uploaded file / logo");

    let html = "";

    if (warnings.length) {
      html += `
        <div class="alert alert-warning">
          <b>Check before production:</b><br>
          ${warnings.map((w) => `<span class="badge badge-danger mr-1">${escapeHtml(w)}</span>`).join("")}
        </div>
      `;
    }

    html += section("Bike / Category", bike);
    html += section("Personalization", personal);
    html += section("Graphics", graphics);
    html += section("Files", files);
    html += section("Other", other);

    return html;
  }

  window.renderOptionsPretty = renderOptionsPretty;

  function optionEditorType(value) {
    if (typeof value === "number") return "number";
    if (typeof value === "boolean") return "boolean";
    if (value !== null && typeof value === "object") return "json";
    return "text";
  }

  function optionEditorValue(value, type) {
    if (type === "json") {
      return JSON.stringify(value, null, 2);
    }
    if (value === null || typeof value === "undefined") {
      return "";
    }
    return String(value);
  }

  function optionTypeOptions(selected) {
    return ["text", "number", "boolean", "json"]
      .map(function (type) {
        return (
          '<option value="' +
          type +
          '"' +
          (type === selected ? " selected" : "") +
          ">" +
          type +
          "</option>"
        );
      })
      .join("");
  }

  function optionGroupForKey(key) {
    const normalized = String(key || "").toLowerCase();

    if (
      key === "Category Info" ||
      normalized.includes("category") ||
      normalized.includes("bike") ||
      normalized.includes("manufacturer")
    ) {
      return "Bike / Category";
    }

    if (
      normalized.includes("name") ||
      normalized.includes("number") ||
      normalized.includes("font")
    ) {
      return "Personalization";
    }

    if (
      normalized.includes("material") ||
      normalized.includes("finish") ||
      normalized.includes("graphics") ||
      normalized.includes("rim") ||
      normalized.includes("fork")
    ) {
      return "Graphics";
    }

    if (
      normalized.includes("file") ||
      normalized.includes("logo") ||
      normalized.includes("upload")
    ) {
      return "Files";
    }

    return "Other";
  }

  function optionEditorRow(key, value) {
    const type = key === "" ? "text" : optionEditorType(value);

    return `
      <div class="customer-option-row py-2 border-bottom border-secondary">
        <div class="form-row align-items-start">
          <div class="col-md-4 mb-2 mb-md-0">
            <input type="text"
                   class="form-control form-control-sm customer-option-key"
                   value="${escapeHtml(key)}"
                   placeholder="Field">
          </div>
          <div class="col-md-2 mb-2 mb-md-0">
            <select class="form-control form-control-sm customer-option-type">
              ${optionTypeOptions(type)}
            </select>
          </div>
          <div class="col-md-5 mb-2 mb-md-0">
            <textarea class="form-control form-control-sm customer-option-value"
                      rows="${type === "json" ? 4 : 1}"
                      placeholder="Value">${escapeHtml(optionEditorValue(value, type))}</textarea>
          </div>
          <div class="col-md-1 text-right">
            <button type="button" class="btn btn-xs btn-outline-danger btn-remove-customer-option">×</button>
          </div>
        </div>
      </div>
    `;
  }

  function renderCustomerOptionsEditor(data) {
    const keys = Object.keys(data || {});
    if (!keys.length) {
      keys.push("");
    }

    const groups = {
      "Bike / Category": [],
      Personalization: [],
      Graphics: [],
      Files: [],
      Other: [],
    };

    keys.forEach(function (key) {
      const value = key === "" ? "" : data[key];
      const groupName = key === "" ? "Other" : optionGroupForKey(key);
      groups[groupName].push(optionEditorRow(key, value));
    });

    let html = "";
    Object.keys(groups).forEach(function (groupName) {
      if (!groups[groupName].length) return;

      html += `
        <div class="card bg-secondary mb-3 customer-option-group" data-option-group="${escapeHtml(groupName)}">
          <div class="card-header py-2">
            <b>${escapeHtml(groupName)}</b>
          </div>
          <div class="card-body py-0 customer-option-group-body">
            ${groups[groupName].join("")}
          </div>
        </div>
      `;
    });

    $("#customerOptionsEditor").html(html);
  }

  function collectCustomerOptionsEditorData() {
    const data = {};

    $("#customerOptionsEditor .customer-option-row").each(function () {
      const $row = $(this);
      const key = $row.find(".customer-option-key").val().trim();
      if (!key) return;

      const type = $row.find(".customer-option-type").val();
      const rawValue = $row.find(".customer-option-value").val();

      if (type === "number") {
        const value = Number(rawValue);
        if (!Number.isFinite(value)) {
          throw new Error('Invalid number for "' + key + '"');
        }
        data[key] = value;
      } else if (type === "boolean") {
        data[key] = /^(1|true|yes|y|ano)$/i.test(String(rawValue).trim());
      } else if (type === "json") {
        data[key] = rawValue.trim() === "" ? null : JSON.parse(rawValue);
      } else {
        data[key] = rawValue;
      }
    });

    return data;
  }

  function renderInternalOptions(data) {
    const visibleKeys = Object.keys(data || {}).filter(function (key) {
      return !String(key).startsWith("_");
    });

    if (!data || visibleKeys.length === 0) {
      return '<div class="text-muted">No internal production blocks yet.</div>';
    }

    let html = "";

    visibleKeys.forEach(function (blockName) {
      html += `
        <div class="card bg-secondary mb-2">
          <div class="card-header py-2">
            <b>${escapeHtml(blockName)}</b>
          </div>
          <div class="card-body py-2">
      `;

      const fields = data[blockName] || {};

      Object.keys(fields).forEach(function (key) {
        html += `
          <div class="mb-1">
            <span class="text-muted">${escapeHtml(key)}:</span>
            <b>${escapeHtml(fields[key])}</b>
          </div>
        `;
      });

      html += `
          </div>
        </div>
      `;
    });

    return html;
  }
  window.renderInternalOptions = renderInternalOptions;

  function renderInternalEditor(data) {
    let html = "";
    const visibleData = {};

    Object.keys(data || {}).forEach(function (blockName) {
      if (String(blockName).startsWith("_")) return;
      visibleData[blockName] = data[blockName];
    });

    if (Object.keys(visibleData).length === 0) {
      Object.assign(visibleData, {
        "Production Info": {
          Note: "",
        },
      });
    }

    Object.keys(visibleData).forEach(function (blockName) {
      const fields = visibleData[blockName] || {};

      html += `
        <div class="card bg-secondary mb-2 internal-block">
          <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <input type="text"
                   class="form-control form-control-sm internal-block-name"
                   value="${escapeHtml(blockName)}"
                   placeholder="Block name"
                   style="max-width:320px;">

            <div>
              <button type="button" class="btn btn-xs btn-outline-light btn-add-internal-field">
                <i class="fas fa-plus"></i> Field
              </button>
              <button type="button" class="btn btn-xs btn-outline-danger btn-remove-internal-block">
                <i class="fas fa-trash"></i>
              </button>
            </div>
          </div>

          <div class="card-body py-2 internal-fields">
      `;

      Object.keys(fields).forEach(function (key) {
        html += `
          <div class="form-row align-items-center mb-2 internal-field">
            <div class="col-md-4">
              <input type="text" class="form-control form-control-sm internal-field-key" value="${escapeHtml(key)}" placeholder="Field name">
            </div>
            <div class="col-md-7">
              <input type="text" class="form-control form-control-sm internal-field-value" value="${escapeHtml(fields[key])}" placeholder="Value">
            </div>
            <div class="col-md-1 text-right">
              <button type="button" class="btn btn-xs btn-outline-danger btn-remove-internal-field">×</button>
            </div>
          </div>
        `;
      });

      html += `
          </div>
        </div>
      `;
    });

    $("#internalBlocksEditor").html(html);
  }
  window.renderInternalEditor = renderInternalEditor;
  function collectInternalEditorData() {
    const data = {};

    $("#internalBlocksEditor .internal-block").each(function () {
      const blockName = $(this).find(".internal-block-name").val().trim();

      if (!blockName) return;

      data[blockName] = {};

      $(this)
        .find(".internal-field")
        .each(function () {
          const key = $(this).find(".internal-field-key").val().trim();
          const value = $(this).find(".internal-field-value").val().trim();

          if (key) data[blockName][key] = value;
        });

      if (Object.keys(data[blockName]).length === 0) {
        delete data[blockName];
      }
    });

    return data;
  }
  window.collectInternalEditorData = collectInternalEditorData;

  function findOpenOrderIdFromElement($el) {
    // profile_orders.php detail row
    const $profileDetailRow = $el.closest(
      "tr.profile-order-detail-row, .profile-order-detail-row",
    );
    if ($profileDetailRow.length) {
      return parseInt($profileDetailRow.data("detail-for"), 10) || 0;
    }

    // orders.php detail wrapper/row
    const $detailWrap = $el.closest(".detail-wrap");
    if ($detailWrap.length) {
      const $detailRow = $detailWrap.closest("tr");
      const $ordersRow = $detailRow.prev(".order-row");

      if ($ordersRow.length) {
        return parseInt($ordersRow.data("order-id"), 10) || 0;
      }
    }

    // fallback: button/select has order id directly
    const directOrderId = $el.data("order-id");
    if (directOrderId) {
      return parseInt(directOrderId, 10) || 0;
    }

    return 0;
  }

  function refreshOrderDetail(orderId) {
    orderId = parseInt(orderId, 10) || 0;

    if (!orderId) {
      location.reload();
      return;
    }

    $.post(
      "scripts/orders/get_order_detail.php",
      {
        order_id: orderId,
      },
      function (res) {
        if (!res || !res.ok) {
          location.reload();
          return;
        }

        // orders.php layout
        const $ordersDetail = $("#detail-" + orderId);
        if ($ordersDetail.length) {
          $ordersDetail.html(res.html).show();
          return;
        }

        // profile_orders.php layout — vždy znova hľadáme element v aktuálnom DOM
        // (nie cez closure premenné, ktoré môžu ukazovať na detached elementy)
        const $profileDetailRow = $(
          '.profile-order-detail-row[data-detail-for="' + orderId + '"]',
        );
        if ($profileDetailRow.length) {
          $profileDetailRow.show();
          // Hľadáme bunku priamo v aktuálnom DOM — nie zo starej premennej
          $profileDetailRow.find("td").first().html(res.html);
          return;
        }

        location.reload();
      },
      "json",
    ).fail(function () {
      location.reload();
    });
  }

  function triggerEnterSave($field) {
    function clickButton($btn, allowHidden) {
      $btn = $btn
        .filter(function () {
          return allowHidden || $(this).is(":visible");
        })
        .filter(":not(:disabled)")
        .first();
      if (!$btn.length) return false;

      $btn.trigger("click");
      return true;
    }

    if (
      $field.is(
        ".order-priority-select, .order-status-select, .order-types-select, .item-status-select",
      )
    ) {
      $field.trigger("change");
      return true;
    }

    const $headerEdit = $field.closest(".order-header-edit");
    if ($headerEdit.length) {
      return clickButton($headerEdit.find(".btn-save-order-header"), true);
    }

    const $invoiceRow = $field.closest(".form-row");
    if ($invoiceRow.find(".btn-add-invoice").length) {
      return clickButton($invoiceRow.find(".btn-add-invoice"));
    }

    const $trackingRow = $field.closest(".form-row");
    if ($trackingRow.find(".btn-add-tracking").length) {
      return clickButton($trackingRow.find(".btn-add-tracking"));
    }

    const $noteEditor = $field.closest(".production-note-editor");
    if ($noteEditor.length) {
      return clickButton($noteEditor.find(".btn-save-production-note"));
    }

    const $manualItemBox = $field.closest(".manual-item-box");
    if ($manualItemBox.length) {
      return clickButton($manualItemBox.find(".btn-add-manual-item"));
    }

    const $itemRow = $field.closest("tr");
    if ($itemRow.length) {
      if ($field.is(".item-waiting-note, .item-expected-date")) {
        if (clickButton($itemRow.find(".btn-save-waiting"), true)) {
          return true;
        }

        const $statusSelect = $itemRow.find(".item-status-select").first();
        if ($statusSelect.length && !$statusSelect.prop("disabled")) {
          $statusSelect.trigger("change");
          return true;
        }
      }

      if (clickButton($itemRow.find(".btn-save-item"))) {
        return true;
      }
    }

    if ($field.closest("#customerOptionsEditBox").length) {
      return clickButton($("#btnSaveCustomerOptions"));
    }

    if ($field.closest("#internalOptionsEditBox").length) {
      return clickButton($("#btnSaveInternalOptions"));
    }

    const $scope = $field.closest(
      ".form-row, .card, .modal-content, .detail-wrap, .profile-order-detail-row",
    );
    return clickButton(
      $scope.find(
        '.btn-save, .btn-add, [class*="btn-save-"], [class*="btn-add-"]',
      ),
    );
  }

  $(document)
    .off("click.saveWaiting", ".btn-save-waiting")
    .on("click.saveWaiting", ".btn-save-waiting", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const $btn = $(this);
      const $row = $btn.closest("tr");
      const itemId =
        parseInt($btn.data("item-id"), 10) ||
        parseInt($row.find(".item-waiting-note").data("item-id"), 10) ||
        parseInt($row.find(".item-expected-date").data("item-id"), 10) ||
        0;
      const orderId = findOpenOrderIdFromElement($btn);

      if (!itemId) {
        alert("Missing item ID");
        return;
      }

      const $noteInput = $('.item-waiting-note[data-item-id="' + itemId + '"]')
        .filter(":visible")
        .last();

      const $dateInput = $('.item-expected-date[data-item-id="' + itemId + '"]')
        .filter(":visible")
        .last();

      const note = $noteInput.val() || "";
      const expectedDate = $dateInput.val() || "";

      $btn.prop("disabled", true);

      $.ajax({
        url: "scripts/orders/update_item_waiting.php",
        method: "POST",
        dataType: "json",
        data: {
          item_id: itemId,
          note: note,
          expected_date: expectedDate,
        },
        success: function (resp) {
          if (!resp || (!resp.success && !resp.ok)) {
            alert(
              resp && (resp.message || resp.error)
                ? resp.message || resp.error
                : "Waiting save failed",
            );
            $btn.prop("disabled", false);
            return;
          }

          $row
            .find(".item-waiting-note, .item-expected-date")
            .css("border-color", "#28a745");

          setTimeout(function () {
            $row
              .find(".item-waiting-note, .item-expected-date")
              .css("border-color", "");
          }, 800);

          $btn.prop("disabled", false);

          if (resp.traffic_summary || resp.order_status) {
            applyTrafficSummaryToRow(
              orderId,
              resp.traffic_summary,
              resp.order_status,
              resp.department_statuses,
              resp.department_labels,
              resp.department_colors,
            );
          }
        },
        error: function (xhr) {
          console.log("WAITING SAVE ERROR", xhr.status, xhr.responseText);
          alert(
            "Waiting save request failed\nHTTP: " +
              xhr.status +
              "\n\n" +
              (xhr.responseText || ""),
          );
          $btn.prop("disabled", false);
        },
      });
    });
  $(document)
    .off("keydown.orderDetailEnterSave")
    .on(
      "keydown.orderDetailEnterSave",
      ".detail-wrap input, .detail-wrap textarea, .detail-wrap select, .profile-order-detail-row input, .profile-order-detail-row textarea, .profile-order-detail-row select, #optionsModal input, #optionsModal textarea, #optionsModal select",
      function (e) {
        if ((e.key && e.key !== "Enter") || (!e.key && e.which !== 13)) return;
        if (e.isComposing || e.shiftKey) return;

        const $field = $(this);
        if ($field.is(":disabled, [readonly]")) return;

        if (triggerEnterSave($field)) {
          e.preventDefault();
          e.stopPropagation();
        }
      },
    );

  /**
   * Aplikuje traffic summary + order status button priamo na riadok tabuľky — čisté DOM, žiadny AJAX.
   * Volaná s dátami ktoré už prišli v odpovedi update_item_status.php.
   */
  function applyTrafficSummaryToRow(
    orderId,
    summary,
    orderStatus,
    departmentStatuses,
    departmentLabels,
    departmentColors,
  ) {
    orderId = parseInt(orderId, 10) || 0;
    if (!orderId) return;

    const $row = $(
      '.profile-order-row[data-order-id="' +
        orderId +
        '"], ' +
        '.order-row[data-order-id="' +
        orderId +
        '"]',
    );
    if (!$row.length) return;

    // ── 1. SEMAFOR ──────────────────────────────────────────────────
    if (summary) {
      let $trafficCell = $row.find("td.traffic-cell").first();

      if (!$trafficCell.length) {
        $trafficCell = $row
          .find("td")
          .filter(function () {
            return (
              $(this)
                .find(".badge")
                .filter(function () {
                  return /^[GPSF]$/.test($(this).text().trim());
                }).length > 0
            );
          })
          .first();
      }

      if ($trafficCell.length) {
        const colorMap = {
          GREEN: "badge-success",
          ORANGE: "badge-warning",
          RED: "badge-danger",
        };
        let html = "";
        ["G", "F", "P", "S"].forEach(function (type) {
          if (!summary[type]) return;
          const state = String(summary[type]).toUpperCase();
          const cls = colorMap[state] || "badge-secondary";
          const deptStatus =
            departmentStatuses && departmentStatuses[type]
              ? String(departmentStatuses[type]).toUpperCase()
              : "";
          const deptLabel =
            departmentLabels && departmentLabels[type]
              ? String(departmentLabels[type]).trim()
              : "";
          const deptColor =
            departmentColors && departmentColors[type]
              ? String(departmentColors[type]).trim()
              : "";
          let style = "font-size:1rem;padding:.5em .7em;";
          let badgeClass = cls;

          if (deptColor) {
            badgeClass = "";
            style +=
              "background-color:" +
              deptColor +
              ";border-color:" +
              deptColor +
              ";color:#fff;";
          }

          html +=
            '<span class="badge ' +
            badgeClass +
            ' mr-1" style="' +
            style +
            '" title="' +
            type +
            " - " +
            (deptLabel || state) +
            (deptStatus ? " (" + deptStatus + ")" : "") +
            '">' +
            type +
            "</span>";
        });
        $trafficCell.html(html);
      }
    }

    // ── 2. STATUS BUTTON ─────────────────────────────────────────────
    if (orderStatus) {
      const $statusCell = $row.find("td[data-status-cell]").first();
      if ($statusCell.length) {
        const s = String(orderStatus).toUpperCase();

        const btnClassMap = {
          NEW: "btn-outline-danger",
          NEED_INFO: "btn-outline-danger",
          IN_PROGRESS: "btn-outline-warning",
          READY_TO_INVOICE: "btn-outline-warning",
          WAITING_PARTS: "btn-outline-warning",
          HOLD: "btn-outline-secondary",
          CANCELLED: "btn-outline-secondary",
          DONE: "btn-outline-success",
          COMPLETED: "btn-outline-success",
          SHIPPED: "btn-outline-success",
          READY: "btn-outline-success",
          READY_TO_SHIP: "btn-outline-success",
        };

        const btnClass = btnClassMap[s] || "btn-outline-secondary";
        const label = s.replace(/_/g, " ") || "-";

        $statusCell.html(
          '<button class="btn btn-xs ' +
            btnClass +
            '" style="pointer-events:none;">' +
            label +
            "</button>",
        );
      }
    }
  }

  function ensureOptionsModal() {
    if ($("#optionsModal").length) {
      return;
    }

    $("body").append(`
      <div class="modal fade" id="optionsModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
          <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
              <h5 class="modal-title">
                <i class="fas fa-list-alt mr-1"></i> Product Detail
              </h5>
              <button type="button" class="close text-light" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>

            <div class="modal-body"></div>

            <div class="modal-footer border-secondary">
              <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>
    `);
  }

  function resetOptionsModalShell() {
    $("#optionsModal .modal-title").html(
      '<i class="fas fa-list-alt mr-1"></i> Product Detail',
    );
    $("#optionsModal .modal-body").html(`
      <div class="mb-3" id="customerOptionsSection">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="text-muted mb-0">
            <i class="fas fa-download mr-1"></i> Customer / Imported Options
          </h6>

          <button type="button" class="btn btn-sm btn-outline-warning" id="btnEditCustomerOptions">
            <i class="fas fa-edit mr-1"></i> Edit options
          </button>
        </div>

        <div id="customerOptionsView"></div>

        <div id="customerOptionsEditBox" style="display:none;">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <small class="text-muted">Changes here rewrite the customer-facing item options.</small>

            <button type="button" class="btn btn-sm btn-outline-info" id="btnAddCustomerOption">
              <i class="fas fa-plus mr-1"></i> Add field
            </button>
          </div>

          <div id="customerOptionsEditor"></div>

          <div class="d-flex justify-content-end mt-2" style="gap:6px;">
            <button type="button" class="btn btn-sm btn-secondary" id="btnCancelCustomerOptions">
              Back
            </button>
            <button type="button" class="btn btn-sm btn-success" id="btnSaveCustomerOptions">
              <i class="fas fa-save mr-1"></i> Save options
            </button>
          </div>
        </div>
      </div>

      <hr class="border-secondary my-3" id="optionsSectionsDivider">

      <div class="mb-2" id="internalOptionsSection">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="text-muted mb-0">
            <i class="fas fa-tools mr-1"></i> Internal Production Blocks
          </h6>

          <button type="button" class="btn btn-sm btn-outline-warning" id="btnEditInternalOptions">
            <i class="fas fa-edit mr-1"></i> Edit internal
          </button>
        </div>

        <div id="internalOptionsView" class="mb-2"></div>

        <div id="internalOptionsEditBox" style="display:none;">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <small class="text-muted">Add production information as blocks and fields.</small>

            <button type="button" class="btn btn-sm btn-outline-info" id="btnAddInternalBlock">
              <i class="fas fa-plus mr-1"></i> Add block
            </button>
          </div>

          <div id="internalBlocksEditor"></div>

          <div class="d-flex justify-content-end mt-2">
            <button type="button" class="btn btn-sm btn-success" id="btnSaveInternalOptions">
              <i class="fas fa-save mr-1"></i> Save internal blocks
            </button>
          </div>
        </div>
      </div>
    `);
  }

  $(document)
    .off("click.orderDetailActions", ".btn-view-options")
    .on("click.orderDetailActions", ".btn-view-options", function (e) {
      e.preventDefault();
      e.stopPropagation();

      ensureOptionsModal();
      resetOptionsModalShell();

      const $btn = $(this);
      const detailTitle = String(
        $btn.attr("data-detail-title") || "Product Detail",
      );
      const data = getOptionsData($btn);
      currentCustomerOptions = stripProtectedOptions(getRawOptionsData($btn));
      currentCanEditOptions =
        String($btn.attr("data-can-edit-options") || "0") === "1";

      $("#optionsModal .modal-title").html(
        '<i class="fas fa-list-alt mr-1"></i> ' + escapeHtml(detailTitle),
      );

      $("#customerOptionsView").html(renderOptionsPretty(data));
      $("#customerOptionsEditBox").hide();
      $("#btnEditCustomerOptions").toggle(currentCanEditOptions);

      currentOptionsItemId = $btn.data("item-id") || 0;

      try {
        currentInternalOptions = JSON.parse(
          $btn.attr("data-internal-options") || "{}",
        );
      } catch (err) {
        currentInternalOptions = {};
      }

      $("#internalOptionsView").html(
        renderInternalOptions(currentInternalOptions),
      );
      $("#internalOptionsEditBox").hide();
      $("#btnEditInternalOptions").toggle(currentCanEditOptions);

      $("#optionsModal").modal("show");
    });

  $(document)
    .off("click.orderDetailActions", ".btn-copy-options")
    .on("click.orderDetailActions", ".btn-copy-options", async function (e) {
      e.preventDefault();
      e.stopPropagation();

      const data = getOptionsData($(this));
      let text = "";

      for (let k in data) {
        if (k.startsWith("_")) continue;
        if (typeof data[k] === "object") continue;
        text += `${k}: ${data[k]}\n`;
      }

      if (!text.trim()) {
        alert("Nothing to copy");
        return;
      }

      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
      } else {
        copyTextFallback(text);
      }

      const $btn = $(this);
      const oldText = $btn.text();
      $btn.text("COPIED");
      setTimeout(() => $btn.text(oldText), 1000);
    });

  $(document)
    .off("click.orderDetailActions", ".btn-copy-inline")
    .on("click.orderDetailActions", ".btn-copy-inline", async function (e) {
      e.preventDefault();
      e.stopPropagation();

      const $btn = $(this);
      const text = $btn.attr("data-copy") || "";

      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
      } else {
        copyTextFallback(text);
      }

      const oldText = $btn.text();
      $btn.text("✔");
      setTimeout(() => $btn.text(oldText), 800);
    });

  $(document)
    .off("click.orderDetailActions", ".btn-assign-item")
    .on("click.orderDetailActions", ".btn-assign-item", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const $btn = $(this);
      const itemId = $btn.data("item-id");

      $.ajax({
        url: "scripts/orders/assign_order_item.php",
        method: "POST",
        dataType: "json",
        data: { item_id: itemId },
        success: function (resp) {
          if (!resp || !resp.ok) {
            alert(resp && resp.error ? resp.error : "Assign item failed");
            return;
          }

          refreshOrderDetail(findOpenOrderIdFromElement($btn));
        },
        error: function (xhr) {
          console.log(xhr.responseText);
          alert("Assign item request failed");
        },
      });
    });
  $(document)
    .off("click.removeAssignment", ".btn-remove-assignment")
    .on("click.removeAssignment", ".btn-remove-assignment", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const $btn = $(this);
      const assignmentId = $btn.data("assignment-id");

      if (!assignmentId) {
        alert("Missing assignment ID");
        return;
      }

      if (!confirm("Remove this assignment?")) {
        return;
      }

      $btn.prop("disabled", true);

      $.ajax({
        url: "scripts/orders/remove_order_assignment.php",
        method: "POST",
        dataType: "json",
        data: {
          assignment_id: assignmentId,
        },
        success: function (resp) {
          if (!resp || !resp.ok) {
            alert(resp && resp.error ? resp.error : "Remove assignment failed");
            $btn.prop("disabled", false);
            return;
          }

          const orderId = findOpenOrderIdFromElement($btn);

          if (orderId) {
            refreshOrderDetail(orderId);
            return;
          }

          location.reload();
        },
        error: function (xhr) {
          console.log(xhr.responseText);
          alert("Remove assignment request failed");
          $btn.prop("disabled", false);
        },
      });
    });

  $(document)
    .off("change.orderDetailActions", ".item-status-select")
    .on("change.orderDetailActions", ".item-status-select", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const $select = $(this);

      // Preskočiť skrytý duplikát select (v td so display:none)
      if ($select.closest("td").css("display") === "none") return;

      const itemId = parseInt($select.data("item-id"), 10) || 0;
      const status = $select.val();
      const orderId = findOpenOrderIdFromElement($select);

      if (!itemId) {
        alert("Missing item ID");
        return;
      }

      $select.prop("disabled", true);

      const $scope = $select.closest("tr");
      const note =
        $scope.find(".item-waiting-note").val() ||
        $('.item-waiting-note[data-item-id="' + itemId + '"]').val() ||
        "";
      const expectedDate =
        $scope.find(".item-expected-date").val() ||
        $('.item-expected-date[data-item-id="' + itemId + '"]').val() ||
        "";

      $.ajax({
        url: "scripts/orders/update_item_status.php",
        method: "POST",
        dataType: "json",
        data: {
          item_id: itemId,
          status: status,
          note: note,
          expected_date: expectedDate,
        },
        success: function (resp) {
          if (!resp || (!resp.success && !resp.ok)) {
            alert(
              resp && (resp.message || resp.error)
                ? resp.message || resp.error
                : "Waiting save failed",
            );
            $select.prop("disabled", false);
            return;
          }

          const resolvedOrderId = findOpenOrderIdFromElement($select);

          // Ak odpoveď obsahuje traffic_summary, aplikujeme ho priamo — bez extra requestu
          if (resp.traffic_summary && resp.order_id) {
            applyTrafficSummaryToRow(
              resp.order_id,
              resp.traffic_summary,
              resp.order_status,
              resp.department_statuses,
              resp.department_labels,
              resp.department_colors,
            );
          }

          refreshOrderDetail(resolvedOrderId);
        },
        error: function (xhr) {
          console.log(xhr.responseText);
          alert("Status update request failed");
          $select.prop("disabled", false);
        },
      });
    });

  $(document)
    .off("click.orderDetailActions", ".btn-edit-production-note")
    .on("click.orderDetailActions", ".btn-edit-production-note", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const $box = $(this).closest(".production-note-box");

      $box.find(".production-note-display").hide();
      $box.find(".btn-edit-production-note").hide();
      $box.find(".production-note-editor").show();
      $box.find(".production-note-input").focus();
    });

  $(document)
    .off("click.orderDetailActions", ".btn-cancel-production-note")
    .on(
      "click.orderDetailActions",
      ".btn-cancel-production-note",
      function (e) {
        e.preventDefault();
        e.stopPropagation();

        const $box = $(this).closest(".production-note-box");

        $box.find(".production-note-editor").hide();
        $box.find(".production-note-display").show();
        $box.find(".btn-edit-production-note").show();
      },
    );

  $(document)
    .off("click.orderDetailActions", ".btn-save-production-note")
    .on("click.orderDetailActions", ".btn-save-production-note", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const $btn = $(this);
      const orderId = $btn.data("order-id");
      const $box = $btn.closest(".production-note-box");
      const note = $box.find(".production-note-input").val();

      $btn.prop("disabled", true).text("Saving...");

      $.post(
        "scripts/orders/update_production_note.php",
        {
          order_id: orderId,
          production_note: note,
        },
        function (res) {
          if (!res || !res.ok) {
            alert(res && res.error ? res.error : "Save failed");
            $btn.prop("disabled", false).text("Save");
            return;
          }

          refreshOrderDetail(orderId);
        },
        "json",
      ).fail(function () {
        alert("Save note request failed");
        $btn.prop("disabled", false).text("Save");
      });
    });

  $(document)
    .off("click.orderDetailActions", "#btnEditCustomerOptions")
    .on("click.orderDetailActions", "#btnEditCustomerOptions", function (e) {
      e.preventDefault();
      e.stopPropagation();

      if (!currentCanEditOptions) return;

      renderCustomerOptionsEditor(currentCustomerOptions);
      $("#customerOptionsView").hide();
      $("#customerOptionsEditBox").show();
      $("#btnEditCustomerOptions").hide();
      $("#optionsSectionsDivider, #internalOptionsSection").hide();
    });

  $(document)
    .off("click.orderDetailActions", "#btnCancelCustomerOptions")
    .on("click.orderDetailActions", "#btnCancelCustomerOptions", function (e) {
      e.preventDefault();
      e.stopPropagation();

      $("#customerOptionsEditBox").hide();
      $("#customerOptionsView").show();
      $("#btnEditCustomerOptions").show();
      $("#optionsSectionsDivider, #internalOptionsSection").show();
    });

  $(document)
    .off("click.orderDetailActions", "#btnAddCustomerOption")
    .on("click.orderDetailActions", "#btnAddCustomerOption", function (e) {
      e.preventDefault();
      e.stopPropagation();

      let $otherGroup = $(
        '#customerOptionsEditor .customer-option-group[data-option-group="Other"]',
      );
      if (!$otherGroup.length) {
        $("#customerOptionsEditor").append(`
          <div class="card bg-secondary mb-3 customer-option-group" data-option-group="Other">
            <div class="card-header py-2">
              <b>Other</b>
            </div>
            <div class="card-body py-0 customer-option-group-body"></div>
          </div>
        `);
        $otherGroup = $(
          '#customerOptionsEditor .customer-option-group[data-option-group="Other"]',
        );
      }

      $otherGroup
        .find(".customer-option-group-body")
        .append(optionEditorRow("", ""));
      $(
        "#customerOptionsEditor .customer-option-row:last .customer-option-key",
      ).focus();
    });

  $(document)
    .off("click.orderDetailActions", ".btn-remove-customer-option")
    .on(
      "click.orderDetailActions",
      ".btn-remove-customer-option",
      function (e) {
        e.preventDefault();
        e.stopPropagation();

        $(this).closest(".customer-option-row").remove();
      },
    );

  $(document)
    .off("change.orderDetailActions", ".customer-option-type")
    .on("change.orderDetailActions", ".customer-option-type", function () {
      const rows = $(this).val() === "json" ? 4 : 1;
      $(this)
        .closest(".customer-option-row")
        .find(".customer-option-value")
        .attr("rows", rows);
    });

  $(document)
    .off("click.orderDetailActions", "#btnSaveCustomerOptions")
    .on("click.orderDetailActions", "#btnSaveCustomerOptions", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const $btn = $(this);
      let data;

      try {
        data = stripProtectedOptions(collectCustomerOptionsEditorData());
      } catch (err) {
        alert(err && err.message ? err.message : "Invalid options data");
        return;
      }

      $btn.prop("disabled", true).text("Saving...");

      $.post(
        "scripts/orders/update_item_options.php",
        {
          item_id: currentOptionsItemId,
          options_json: JSON.stringify(data),
        },
        function (res) {
          if (!res || (!res.ok && !res.success)) {
            alert(
              res && (res.error || res.message)
                ? res.error || res.message
                : "Save failed",
            );
            $btn.prop("disabled", false).text("Save options");
            return;
          }

          $("#optionsModal").modal("hide");
          refreshOrderDetail(
            findOpenOrderIdFromElement(
              $(
                '.btn-view-options[data-item-id="' +
                  currentOptionsItemId +
                  '"]',
              ),
            ),
          );
        },
        "json",
      ).fail(function (xhr) {
        console.log(xhr.responseText);
        alert("Save options request failed");
        $btn.prop("disabled", false).text("Save options");
      });
    });

  $(document)
    .off("click.orderDetailActions", "#btnEditInternalOptions")
    .on("click.orderDetailActions", "#btnEditInternalOptions", function (e) {
      e.preventDefault();
      e.stopPropagation();

      renderInternalEditor(currentInternalOptions);

      $("#internalOptionsEditBox").show();
      $("#btnEditInternalOptions").hide();
    });

  $(document)
    .off("click.orderDetailActions", "#btnAddInternalBlock")
    .on("click.orderDetailActions", "#btnAddInternalBlock", function (e) {
      e.preventDefault();
      e.stopPropagation();

      $("#internalBlocksEditor").append(`
        <div class="card bg-secondary mb-2 internal-block">
          <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <input type="text" class="form-control form-control-sm internal-block-name" value="" placeholder="Block name" style="max-width:320px;">
            <div>
              <button type="button" class="btn btn-xs btn-outline-light btn-add-internal-field">
                <i class="fas fa-plus"></i> Field
              </button>
              <button type="button" class="btn btn-xs btn-outline-danger btn-remove-internal-block">
                <i class="fas fa-trash"></i>
              </button>
            </div>
          </div>
          <div class="card-body py-2 internal-fields"></div>
        </div>
      `);
    });

  $(document)
    .off("click.orderDetailActions", ".btn-add-internal-field")
    .on("click.orderDetailActions", ".btn-add-internal-field", function (e) {
      e.preventDefault();
      e.stopPropagation();

      $(this).closest(".internal-block").find(".internal-fields").append(`
        <div class="form-row align-items-center mb-2 internal-field">
          <div class="col-md-4">
            <input type="text" class="form-control form-control-sm internal-field-key" placeholder="Field name">
          </div>
          <div class="col-md-7">
            <input type="text" class="form-control form-control-sm internal-field-value" placeholder="Value">
          </div>
          <div class="col-md-1 text-right">
            <button type="button" class="btn btn-xs btn-outline-danger btn-remove-internal-field">×</button>
          </div>
        </div>
      `);
    });

  $(document)
    .off("click.orderDetailActions", ".btn-remove-internal-field")
    .on("click.orderDetailActions", ".btn-remove-internal-field", function (e) {
      e.preventDefault();
      e.stopPropagation();

      $(this).closest(".internal-field").remove();
    });

  $(document)
    .off("click.orderDetailActions", ".btn-remove-internal-block")
    .on("click.orderDetailActions", ".btn-remove-internal-block", function (e) {
      e.preventDefault();
      e.stopPropagation();

      if (confirm("Remove this block?")) {
        $(this).closest(".internal-block").remove();
      }
    });

  $(document)
    .off("click.orderDetailActions", "#btnSaveInternalOptions")
    .on("click.orderDetailActions", "#btnSaveInternalOptions", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const data = collectInternalEditorData();

      $.post(
        "scripts/orders/update_item_internal_options.php",
        {
          item_id: currentOptionsItemId,
          internal_options_json: JSON.stringify(data),
        },
        function (res) {
          if (!res || (!res.ok && !res.success)) {
            alert(
              res && (res.error || res.message)
                ? res.error || res.message
                : "Save failed",
            );
            return;
          }

          $("#optionsModal").modal("hide");
          refreshOrderDetail(
            findOpenOrderIdFromElement(
              $(
                '.btn-view-options[data-item-id="' +
                  currentOptionsItemId +
                  '"]',
              ),
            ),
          );
        },
        "json",
      ).fail(function (xhr) {
        console.log(xhr.responseText);
        alert("Save internal options request failed");
      });
    });

  $(document)
    .off("change.orderPriority", ".order-priority-select")
    .on("change.orderPriority", ".order-priority-select", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const $select = $(this);
      const orderId = $select.data("order-id");
      const priority = $select.val();

      if (!orderId || priority === "") {
        alert("Missing order ID or priority");
        return;
      }

      $select.prop("disabled", true);

      $.ajax({
        url: "scripts/orders/update_order_priority.php",
        method: "POST",
        dataType: "json",
        data: {
          order_id: orderId,
          priority: priority,
        },
        success: function (resp) {
          if (!resp || !resp.ok) {
            alert(resp && resp.error ? resp.error : "Priority update failed");
            $select.prop("disabled", false);
            return;
          }

          location.reload();
        },
        error: function (xhr) {
          console.log(xhr.responseText);
          alert("Priority update request failed");
          $select.prop("disabled", false);
        },
      });
    });

  $(document)
    .off("change.orderStatus", ".order-status-select")
    .on("change.orderStatus", ".order-status-select", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const $select = $(this);
      const orderId = $select.data("order-id");
      const status = $select.val();
      const prevStatus = (
        $select.data("prev-status") ||
        $select.find("option:selected").data("prev") ||
        ""
      ).toUpperCase();

      if (!orderId || !status) {
        alert("Missing order ID or status");
        return;
      }

      // Ochrana: zmena Z stavu PENDING je nezvratná — vyžaduj potvrdenie
      const wasPending =
        prevStatus === "PENDING" ||
        ($select.find('option[value="PENDING"]').length &&
          $select.data("original-status") === "PENDING");

      if (wasPending && status !== "PENDING") {
        const ok = confirm(
          "⚠️ Táto objednávka je PENDING (nezaplatená).\n\n" +
            'Zmenou statusu na "' +
            status.replace(/_/g, " ") +
            '" potvrzuješ, že platba bola prijatá.\n\n' +
            "Pokračovať?",
        );
        if (!ok) {
          $select.val("PENDING");
          return;
        }
      }

      $select.prop("disabled", true);

      $.ajax({
        url: "scripts/orders/update_order_status.php",
        method: "POST",
        dataType: "json",
        data: {
          order_id: orderId,
          status: status,
        },
        success: function (resp) {
          if (!resp || !resp.ok) {
            alert(resp && resp.error ? resp.error : "Status update failed");
            $select.prop("disabled", false);
            return;
          }

          // V profile contexte refreshujeme zoznam (lebo sa mohol zmeniť status badge)
          // + znova otvoríme detail — bez full reload
          if (typeof window.refreshProfileOrdersList === "function") {
            window.refreshProfileOrdersList(orderId);
            return;
          }

          location.reload();
        },
        error: function (xhr) {
          console.log(xhr.responseText);
          alert("Status update request failed");
          $select.prop("disabled", false);
        },
      });
    });
})();

// -- Edit header button toggle --
// DOM: btn a .order-header-edit su surodenci — treba ist cez spolocneho rodica

function getHeaderPanel($btn) {
  // btn je v div.d-flex, ktory je child toho isteho parenta ako .order-header-edit
  return $btn.closest("div").parent().find(".order-header-edit").first();
}

function resetEditHeaderBtn($btn) {
  $btn
    .data("mode", "edit")
    .removeClass("btn-warning")
    .addClass("btn-light")
    .html("✏️ Edit header");
}

$(document)
  .off("click.editHeaderToggle", ".btn-edit-order-header")
  .on("click.editHeaderToggle", ".btn-edit-order-header", function () {
    var $btn = $(this);
    var mode = $btn.data("mode") || "edit";
    var $panel = getHeaderPanel($btn);

    if (!$panel.length) {
      console.warn("[editHeader] .order-header-edit not found");
      return;
    }

    if (mode === "edit") {
      $panel.slideDown(150);
      $btn
        .data("mode", "save")
        .removeClass("btn-light")
        .addClass("btn-warning")
        .html("💾 Save changes");
    } else {
      $panel.find(".btn-save-order-header").trigger("click");
    }
  });

$(document)
  .off("click.editHeaderCancel", ".btn-cancel-order-header")
  .on("click.editHeaderCancel", ".btn-cancel-order-header", function () {
    var $panel = $(this).closest(".order-header-edit");
    $panel.slideUp(150);
    resetEditHeaderBtn($panel.parent().find(".btn-edit-order-header").first());
  });

$(document)
  .off("click.editHeaderSave", ".btn-save-order-header")
  .on("click.editHeaderSave", ".btn-save-order-header", function () {
    var $panel = $(this).closest(".order-header-edit");
    resetEditHeaderBtn($panel.parent().find(".btn-edit-order-header").first());
  });
